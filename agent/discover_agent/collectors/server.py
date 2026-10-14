"""Server identity: names, addresses, OS and virtualization."""

import json
import platform
import socket

from discover_agent import util


def parse_ip_addr(json_str):
    interfaces = []
    for iface in json.loads(json_str):
        if iface.get("ifname") == "lo":
            continue
        addresses = [
            f"{a['local']}/{a['prefixlen']}"
            for a in iface.get("addr_info", [])
            if a.get("scope") != "link"
        ]
        interfaces.append({"name": iface["ifname"], "addresses": addresses})
    return interfaces


def parse_route_get(json_str):
    routes = json.loads(json_str)
    return routes[0].get("prefsrc") if routes else None


def parse_os_release(text):
    for line in text.splitlines():
        if line.startswith("PRETTY_NAME="):
            return line.split("=", 1)[1].strip().strip('"')
    return None


def _safe(fn, default=None):
    try:
        return fn()
    except (util.CommandError, ValueError, IndexError, KeyError):
        return default


def collect_server(runner=util.run, reader=util.read_text):
    os_release = reader("/etc/os-release") or ""
    return {
        "hostname": socket.gethostname(),
        "fqdn": socket.getfqdn(),
        "machine_id": (reader("/etc/machine-id") or "").strip() or None,
        "primary_ip": _safe(lambda: parse_route_get(runner(["ip", "-j", "route", "get", "1.1.1.1"]))),
        "os": parse_os_release(os_release),
        "kernel": platform.release(),
        # systemd-detect-virt exits 1 and prints "none" on bare metal.
        "virtualization": _safe(lambda: runner(["systemd-detect-virt"]).strip(), "none"),
        "interfaces": _safe(lambda: parse_ip_addr(runner(["ip", "-j", "addr"])), []),
    }
