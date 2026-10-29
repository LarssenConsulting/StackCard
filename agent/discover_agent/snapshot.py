"""Assemble collector results into one snapshot; one failing collector never stops the others."""

import socket

from discover_agent import __version__, util
from discover_agent.collectors import apache, dbrefs, docker, firewall, mysql, node, server, sites, techs

SCHEMA_VERSION = 1


def _describe(exc):
    if isinstance(exc, (util.CollectorError, util.CommandError)):
        return str(exc)
    return f"{type(exc).__name__}: {exc}"


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
            errors.append({"collector": "apache", "message": f"{vhost.get('server_name')}: {_describe(exc)}"})
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


def build_snapshot(collectors=None):
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
            errors.append({"collector": name, "message": _describe(exc)})
    snap["errors"] = errors
    return snap
