"""Assemble collector results into one snapshot; one failing collector never stops the others."""

import socket

from discover_agent import __version__, sections, util
from discover_agent.redact import redact
from discover_agent.collectors import apache, dbrefs, docker, firewall, mysql, node, server, sites, techs

SCHEMA_VERSION = 1
MESSAGE_MAX = 4000


def _message(text):
    """errors[].message must be 1 to 4000 characters or the central refuses the inventory."""
    return (text or "unknown error")[:MESSAGE_MAX]


def _describe(exc):
    """The message of a failure, masked: it can quote a command's stderr and reaches `snapshots.payload`."""
    text = redact(str(exc))
    if isinstance(exc, (util.CollectorError, util.CommandError)):
        return text
    return f"{type(exc).__name__}: {text}"  # outside redact: `KeyError:` would read as a secret's name


def collect_apache(
    errors,
    has_apache=lambda: util.which("apache2ctl"),
    runner=util.run,
    load_vhosts=None,
    build_context=None,
    classify=sites.classify,
    find_db_refs=dbrefs.find_db_refs,
    detect_technologies=techs.detect_technologies,
):
    if not has_apache():
        return None
    load_vhosts = load_vhosts or (lambda: apache.load_vhosts(runner=runner))
    build_context = build_context or (lambda: sites.build_context(runner=runner))
    try:
        version = apache.parse_version(runner(["apache2", "-v"]))
    except util.CommandError:
        version = None
    context = build_context()
    collected, technologies = [], {}
    for vhost in load_vhosts():
        try:
            site = classify(vhost, context)
            site["db_refs"] = find_db_refs(site.get("app_dir"))
            # The HTTP and HTTPS vhosts of a site share a directory: read its lock files once.
            app_dir = site.get("app_dir")
            if app_dir not in technologies:
                technologies[app_dir] = detect_technologies(app_dir)
            site["technologies"] = technologies[app_dir]
        except Exception as exc:  # one odd vhost must not hide the others
            errors.append({
                "collector": "apache",
                "message": _message(f"{vhost.get('server_name')}: {_describe(exc)}"),
            })
            continue
        collected.append(site)
    return {"version": version, "sites": collected}


def _minimal_server():
    """The central needs an identity: without it the whole snapshot would be refused."""
    return {
        "hostname": socket.gethostname(), "fqdn": None, "machine_id": None, "primary_ip": None,
        "os": None, "kernel": None, "virtualization": None, "interfaces": [],
    }


def default_collectors(errors):
    return {
        "server": server.collect_server,
        "apache": lambda: collect_apache(errors),
        "mysql": mysql.collect_mysql,
        "node": node.collect_node,
        "docker": docker.collect_docker,
        "ufw": firewall.collect_firewall,
    }


# Outcomes that are expected, not failures: they stay out of errors[].
_QUIET_SECTION_ERRORS = (None, "not_installed", "skipped_not_due")


def _utf8_safe(value):
    """The same structure with lone surrogates replaced, in keys too: they cannot be sent as UTF-8.

    Legacy collectors keep undecodable bytes of paths that way (`os.readlink`).
    """
    if isinstance(value, str):
        return value.encode("utf-8", "replace").decode("utf-8")
    if isinstance(value, dict):
        return {_utf8_safe(key): _utf8_safe(item) for key, item in value.items()}
    if isinstance(value, (list, tuple)):
        return [_utf8_safe(item) for item in value]
    return value


def build_snapshot(collectors=None, sections_due=None, section_runner=None):
    """`sections_due` lists the weekly sections to run; None means levels 1 and 2 only."""
    errors = []
    collectors = collectors if collectors is not None else default_collectors(errors)
    snap = {
        "schema_version": SCHEMA_VERSION,
        "collected_at": util.now_utc(),
        "agent_version": __version__,
    }
    for name, collect in collectors.items():
        try:
            snap[name] = collect()
        except Exception as exc:
            snap[name] = _minimal_server() if name == "server" else None
            errors.append({"collector": name, "message": _message(_describe(exc))})
    runner = section_runner or sections.run_all
    try:
        envelopes = sections.enforce_caps(runner(set(sections_due or ())))
    except Exception as exc:  # the sections must never cost the rest of the inventory
        envelopes = {}
        errors.append({"collector": "sections", "message": _message(_describe(exc))})
    for name, envelope in envelopes.items():
        if envelope.get("error") not in _QUIET_SECTION_ERRORS:
            errors.append({"collector": f"sections.{name}", "message": _message(envelope["error"])})
    snap["sections"] = envelopes
    snap["errors"] = errors
    return _utf8_safe(snap)
