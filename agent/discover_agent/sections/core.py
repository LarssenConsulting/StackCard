"""Section runner: isolation, timeouts, envelopes, size caps and the weekly schedule.

Section modules import from here (never from the package `__init__`), which
keeps the registry free of circular imports.
"""

import hashlib
import json
import threading
from dataclasses import dataclass, field
from datetime import datetime, timedelta, timezone

from discover_agent import util
from discover_agent.redact import redact

# Order of the spec; also the order of the envelopes sent to the central.
SECTION_NAMES = (
    "packages", "apt_sources", "apt_updates", "kernel", "restart_needed", "kernel_surface", "network", "php",
    "apache", "site_dependencies", "node_apps", "docker", "persistence", "integrity", "code_patterns",
)
# Level 3: slow, so collected on their own schedule and given a longer timeout.
WEEKLY = ("integrity", "code_patterns")

TIMEOUT = 30
TIMEOUT_WEEKLY = 300
# `apt_updates` runs three commands in turn; its budget covers all of them plus a margin.
REFRESH_TIMEOUT = 120
SIMULATION_TIMEOUT = 60
SHOWHOLD_TIMEOUT = 15
TIMEOUT_APT = REFRESH_TIMEOUT + SIMULATION_TIMEOUT + SHOWHOLD_TIMEOUT + 15
SECTION_MAX = 4 * 1024 * 1024
TOTAL_MAX = 16 * 1024 * 1024
RETRY_AFTER_FAILURE_DAYS = 1

# name -> collect() returning Collected; the section modules register themselves.
REGISTRY = {}

_DATE_FORMAT = "%Y-%m-%dT%H:%M:%SZ"


@dataclass
class Collected:
    """What a section module returns: its data, plus sub-sources that could not be read."""

    data: object
    source_errors: dict = field(default_factory=dict)


MESSAGE_MAX = 4000  # keeps a long error from getting the whole inventory rejected


def describe(exc):
    if isinstance(exc, util.CommandError):
        # A failing command can echo a credentialed URL; other messages are ours.
        text = redact(str(exc) or type(exc).__name__)
    elif isinstance(exc, util.CollectorError):
        text = str(exc) or type(exc).__name__
    else:
        text = f"{type(exc).__name__}: {exc}"
    return text[:MESSAGE_MAX]


def read_source(errors, name, fn):
    """Call `fn`; on failure record why in `errors[name]` and return None."""
    try:
        return fn()
    except util.NotInstalled:
        errors[name] = "not_installed"
    except Exception as exc:
        errors[name] = describe(exc)
    return None


def canonical_sha256(data):
    text = json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False)
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def timeout_for(name):
    if name == "apt_updates":
        return TIMEOUT_APT
    return TIMEOUT_WEEKLY if name in WEEKLY else TIMEOUT


def _failed(collected_at, error):
    return {"collected_at": collected_at, "error": error, "source_errors": {}, "data": None, "sha256": None}


def _run_section(collect, timeout, collected_at):
    outcome = {}

    def target():
        try:
            outcome["result"] = collect()
        except BaseException as exc:  # a section must never take the agent down
            outcome["exc"] = exc

    # Daemon thread: an abandoned section never blocks exit; its commands have their own timeout.
    thread = threading.Thread(target=target, daemon=True)
    thread.start()
    thread.join(timeout)
    if thread.is_alive():
        return _failed(collected_at, "timeout")
    exc = outcome.get("exc")
    if isinstance(exc, util.NotInstalled):
        return _failed(collected_at, "not_installed")
    if exc is not None:
        return _failed(collected_at, describe(exc))
    result = outcome["result"]
    try:
        if not isinstance(result, Collected):
            raise TypeError(f"a section must return Collected, got {type(result).__name__}")
        envelope = {
            "collected_at": collected_at,
            "error": None,
            "source_errors": dict(result.source_errors),
            "data": result.data,
            "sha256": canonical_sha256(result.data),
        }
        # Same encoding as the canonical JSON: a lone surrogate fails here, not at the central.
        json.dumps(envelope["source_errors"], ensure_ascii=False).encode("utf-8")
    except Exception as exc:  # data that cannot be serialised fails this section only
        return _failed(collected_at, describe(exc))
    return envelope


def run_all(due, registry=None, timeouts=None, now=util.now_utc):
    """Run the registered sections one after the other and return their envelopes.

    `due` holds the weekly sections to run; the other weekly ones are reported as
    skipped. Sections with no registered module are left out.
    """
    registry = REGISTRY if registry is None else registry
    timeouts = timeouts or {}
    envelopes = {}
    for name in SECTION_NAMES:
        if name not in registry:
            continue
        if name in WEEKLY and name not in due:
            envelopes[name] = {"error": "skipped_not_due"}
            continue
        envelopes[name] = _run_section(registry[name], timeouts.get(name, timeout_for(name)), now())
    return envelopes


def _size(data):
    return len(json.dumps(data, sort_keys=True, separators=(",", ":"), ensure_ascii=False).encode("utf-8"))


def enforce_caps(envelopes, section_max=None, total_max=None):
    """Turn oversized sections into `too_large`, largest first, until the total fits."""
    section_max = SECTION_MAX if section_max is None else section_max
    total_max = TOTAL_MAX if total_max is None else total_max
    sizes = {name: _size(env["data"]) for name, env in envelopes.items() if env.get("data") is not None}

    def drop(name):
        envelopes[name].update(error="too_large", data=None, sha256=None)
        del sizes[name]

    for name in [n for n, size in sizes.items() if size > section_max]:
        drop(name)
    while sizes and sum(sizes.values()) > total_max:
        drop(max(sizes, key=sizes.get))
    return envelopes


def _parse(stamp):
    return datetime.strptime(stamp, _DATE_FORMAT).replace(tzinfo=timezone.utc)


def _stamp(entry, key):
    """The date stored in a state entry; None when absent or unreadable."""
    try:
        return _parse(entry[key])
    except (KeyError, TypeError, ValueError):
        return None


def due_weekly(state, interval_days, now):
    """Weekly sections with no schedule yet, or whose `next_run` has passed.

    A section that was run but whose result was not delivered waits 24 h after
    `last_attempt`: a delivered result always leaves `next_run` later than
    `last_attempt`, so `next_run` at or before it means the schedule never moved.

    `interval_days` is accepted for symmetry with `record_weekly`: the schedule
    itself lives in `state`, written with that interval.
    """
    current = _parse(now)
    weekly = state.get("weekly")
    weekly = weekly if isinstance(weekly, dict) else {}
    hold = timedelta(days=RETRY_AFTER_FAILURE_DAYS)
    due = set()
    for name in WEEKLY:
        entry = weekly.get(name)
        next_run, attempt = _stamp(entry, "next_run"), _stamp(entry, "last_attempt")
        if next_run is not None and next_run > current:
            continue
        undelivered = attempt is not None and (next_run is None or next_run <= attempt)
        # `attempt` in the future: the clock moved back, and that must not hold a section for long.
        if undelivered and timedelta(0) <= current - attempt < hold:
            continue
        due.add(name)
    return due


def _weekly_entry(state, name):
    if not isinstance(state.get("weekly"), dict):
        state["weekly"] = {}
    if not isinstance(state["weekly"].get(name), dict):
        state["weekly"][name] = {}
    return state["weekly"][name]


def record_attempt(state, envelopes, now):
    """Note each weekly section that was run, whatever becomes of the send."""
    for name in WEEKLY:
        if envelopes.get(name, {}).get("error", "skipped_not_due") != "skipped_not_due":
            _weekly_entry(state, name)["last_attempt"] = now


def record_weekly(state, envelopes, interval_days, now):
    """Schedule the next run of each weekly section that was actually collected.

    Call it only once the central acknowledged the send: a lost send is retried.
    """
    current = _parse(now)
    for name in WEEKLY:
        error = envelopes.get(name, {}).get("error", "skipped_not_due")
        if error == "skipped_not_due":
            continue
        days = interval_days if error in (None, "not_installed") else RETRY_AFTER_FAILURE_DAYS
        _weekly_entry(state, name)["next_run"] = util.iso_utc(current + timedelta(days=days))
