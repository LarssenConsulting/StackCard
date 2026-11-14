"""Section `apt_updates`: pending upgrades by simulation (`apt-get -s dist-upgrade`) and held packages.

Read-only by design: the only commands ever run are `apt-get update -q` (when the
package lists are older than the configured delay), `apt-get -s ... dist-upgrade`
and `apt-mark showhold`. Nothing is installed, and a held APT lock is never awaited.
"""

import os
import re
from datetime import datetime, timedelta, timezone

from discover_agent import config, util
from discover_agent.sections.core import (
    REFRESH_TIMEOUT, SHOWHOLD_TIMEOUT, SIMULATION_TIMEOUT, Collected, describe, read_source,
)
from discover_agent.sections.tools import run_tool

APT_GET = "/usr/bin/apt-get"
APT_MARK = "/usr/bin/apt-mark"
STAMP = "/var/lib/apt/periodic/update-success-stamp"
LISTS_DIR = "/var/lib/apt/lists"
MARKER_NAME = "apt-refresh-attempt"
# `-o DPkg::Lock::Timeout=0`: a site-wide apt.conf lock timeout must never make us wait.
UPDATE_CMD = [APT_GET, "update", "-q", "-o", "DPkg::Lock::Timeout=0"]
# Fixed environment for every APT command: nothing of the agent's own (APT_CONFIG...) is passed on.
CLEAN_ENV = {"LC_ALL": "C", "LANGUAGE": "C", "PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}

_INST = re.compile(
    r"^Inst (\S+)(?: \[([^\]]+)\])? \((\S+) (.*?) \[([^\]]+)\]\)\s*(?:\[[^\]]*\]\s*)*$"
)
_LOCKED = ("Could not get lock", "is held by process")


def _mtime(path, stat):
    try:
        return datetime.fromtimestamp(stat(path).st_mtime, timezone.utc)
    except (OSError, ValueError, OverflowError):
        return None


def cache_updated_at(stat=os.stat, listdir=os.listdir):
    """When the package lists were last refreshed, or None when nothing says."""
    stamp = _mtime(STAMP, stat)
    if stamp is not None:
        return stamp
    # No stamp: the lists directory is rewritten by every update run, unlike the
    # Release files, whose mtime is the archive's own date.
    directory = _mtime(LISTS_DIR, stat)
    if directory is not None:
        return directory
    try:
        names = listdir(LISTS_DIR)
    except OSError:
        return None
    times = [
        t for name in names
        if name.endswith("InRelease") or name.endswith("_Release")
        if (t := _mtime(f"{LISTS_DIR}/{name}", stat)) is not None
    ]
    return max(times, default=None)


def _suite(origin):
    origin = origin.strip()
    return origin.rsplit("/", 1)[1] if "/" in origin else origin


def parse_simulation(text):
    """`Inst` lines of `apt-get -s dist-upgrade`; `Conf`, `Remv` and the rest are ignored."""
    upgrades = []
    for line in text.splitlines():
        match = _INST.match(line)
        if not match:
            continue
        name, installed, candidate, origins, arch = match.groups()
        suites = sorted({_suite(o) for o in origins.split(",") if o.strip()})
        upgrades.append({
            "package": name.split(":", 1)[0],
            "arch": arch,
            "installed": installed,
            "candidate": candidate,
            "origins": suites,
            "security": any(s.endswith("-security") for s in suites),
        })
    upgrades.sort(key=lambda u: (u["package"], u["arch"]))
    return upgrades


def parse_held(text):
    return sorted({line.strip() for line in text.splitlines() if line.strip()})


def _failure_line(err):
    lines = [line.strip() for line in err.splitlines() if line.strip()]
    return next((line for line in lines if line.startswith("E:")), lines[0] if lines else "")


def _refresh(errors, run_full):
    try:
        code, _out, err = run_full(list(UPDATE_CMD), timeout=REFRESH_TIMEOUT, env=dict(CLEAN_ENV))
    except util.CommandTimeout:
        errors["apt_update"] = "timeout"
        return
    except util.CommandError as exc:
        if str(exc).startswith("command not found"):
            return  # the other sources report `not_installed`
        errors["apt_update"] = describe(exc)
        return
    if code == 0:
        return
    if any(marker in err for marker in _LOCKED):
        errors["apt_update"] = "locked"
    else:
        errors["apt_update"] = describe(util.CommandError(f"apt-get update exited {code}: {_failure_line(err)[:500]}"))


def _is_stale(moment, hours, current):
    """True when `moment` is unknown, in the future (clock skew) or older than `hours`."""
    if moment is None or moment > current:
        return True
    return current - moment > timedelta(hours=hours)


def write_marker(path, now_text):
    """The agent's own record of its last refresh attempt (0600); failures are not fatal."""
    try:
        fd = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
        with os.fdopen(fd, "w") as fh:
            fh.write(now_text + "\n")
    except OSError:
        pass


def _count_unparsed(text):
    return sum(1 for line in text.splitlines() if line.startswith("Inst ") and not _INST.match(line))


def collect(runner=util.run_c, run_full=util.run_full, now=util.now_utc, refresh_hours=None,
            stat=os.stat, listdir=os.listdir, marker_path=None, write_marker=write_marker):
    errors = {}
    paths = config.Paths()
    hours = refresh_hours or config.read_apt_refresh_hours(paths)
    marker_path = marker_path or os.path.join(paths.state_dir, MARKER_NAME)
    now_text = now()
    current = datetime.strptime(now_text, "%Y-%m-%dT%H:%M:%SZ").replace(tzinfo=timezone.utc)
    updated = cache_updated_at(stat, listdir)
    # Both must be old: the cache stamp only moves on a fully successful update, so a
    # broken repository alone must not make every hourly run retry.
    if _is_stale(updated, hours, current) and _is_stale(_mtime(marker_path, stat), hours, current):
        write_marker(marker_path, now_text)  # before the run: a crash or timeout counts as an attempt
        _refresh(errors, run_full)  # one attempt, never retried
        updated = cache_updated_at(stat, listdir)
    unparsed = []

    def simulate():
        text = run_tool(
            runner, [APT_GET, "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"],
            timeout=SIMULATION_TIMEOUT, env=dict(CLEAN_ENV))
        unparsed.append(_count_unparsed(text))
        return parse_simulation(text)

    upgrades = read_source(errors, "upgrades", simulate)
    if unparsed and unparsed[0]:
        errors["upgrades_unparsed"] = f"{unparsed[0]} lignes non lues"
    held = read_source(
        errors, "held",
        lambda: parse_held(run_tool(runner, [APT_MARK, "showhold"], timeout=SHOWHOLD_TIMEOUT, env=dict(CLEAN_ENV))))
    if errors.get("upgrades") == "not_installed" and errors.get("held") == "not_installed":
        raise util.NotInstalled("apt-get")
    data = {
        "cache_updated_at": util.iso_utc(updated) if updated is not None else None,
        "upgrades": upgrades,
        "held": held,
    }
    return Collected(data, errors)
