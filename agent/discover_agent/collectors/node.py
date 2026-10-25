"""Node.js applications listening on this server, whether or not a vhost points at them.

Nothing is executed as the application user: the process is found with `ss`,
described from /proc, and its package.json is read like any site file.
"""

import os
import re

from discover_agent import util
from discover_agent.collectors import sites, techs

_SS_PROCESS = re.compile(r'\("([^"]*)",pid=(\d+)')
_NODE_IN_PATH = re.compile(r"/v?(\d+\.\d+\.\d+)/bin/node(js)?$")
_DPKG_VERSION = re.compile(r"^(?:\d+:)?(\d+(?:\.\d+){0,3})")
SYSTEM_NODE = ("/usr/bin/node", "/usr/bin/nodejs", "/usr/local/bin/node")


def parse_ss_sockets(text):
    """`ss -Hltnp` -> [(address, port, [pid, ...])]"""
    sockets = []
    for line in text.splitlines():
        fields = line.split()
        if len(fields) < 4:
            continue
        address, _, port = fields[3].rpartition(":")
        pids = [int(pid) for _, pid in _SS_PROCESS.findall(line)]
        if port.isdigit() and pids:
            sockets.append((address.strip("[]"), int(port), pids))
    return sockets


def node_version_from_path(exe):
    """nvm, n and fnm install each version under its own directory."""
    match = _NODE_IN_PATH.search(exe or "")
    return match.group(1) if match else None


def parse_dpkg_version(text):
    """'1:20.18.0-1nodesource1' or '18.19.1+dfsg-6ubuntu5' -> the upstream version."""
    match = _DPKG_VERSION.match(text.strip())
    return match.group(1) if match else None


def is_node(exe):
    return os.path.basename(exe or "") in ("node", "nodejs")


def collect_node(
    runner=util.run,
    exe_of=lambda pid: _readlink(f"/proc/{pid}/exe"),
    proc_info=sites.proc_info,
    read_package=techs.read_package,
    detect_technologies=techs.detect_technologies,
):
    sockets = parse_ss_sockets(runner(["ss", "-Hltnp"]))
    system_version = []

    def version_of(exe):
        if exe not in SYSTEM_NODE:
            return node_version_from_path(exe)
        if not system_version:
            try:
                output = runner(["dpkg-query", "-W", "-f", "${Version}", "nodejs"])
            except util.CommandError:
                output = ""
            system_version.append(parse_dpkg_version(output))
        return system_version[0]

    apps = {}
    for address, port, pids in sockets:
        # Cluster workers share the socket: the first Node process stands for the app.
        pid = next((p for p in pids if is_node(exe_of(p))), None)
        info = proc_info(pid) if pid else None
        if not info:
            continue
        key = (info["user"], info["cwd"], info["process_manager"])
        app = apps.get(key)
        if app is None:
            name, version = read_package(info["cwd"])
            app = apps[key] = {
                "name": name,
                "version": version,
                "node_version": version_of(exe_of(pid)),
                "listen": [],
                "user": info["user"],
                "group": info["group"],
                "app_dir": info["cwd"],
                "process_manager": info["process_manager"],
                "technologies": detect_technologies(info["cwd"]),
            }
        listen = {"address": address, "port": port}
        if listen not in app["listen"]:
            app["listen"].append(listen)
    return {"apps": list(apps.values())}


def _readlink(path):
    try:
        return os.readlink(path)
    except OSError:
        return None
