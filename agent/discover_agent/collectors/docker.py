"""Docker containers of this server, read from the Engine API on its local Unix socket.

Only GET requests are sent. Environment variables and labels often hold
passwords: none are kept, except the Compose labels naming the project.
"""

import http.client
import json
import os
import re
import socket
import stat

from discover_agent import util

SOCKET = "/var/run/docker.sock"
LIMIT = 16 * 1024 * 1024

_ID = re.compile(r"^[0-9a-f]{12,64}$")
_COMPOSE = "com.docker.compose."
_NEVER_STARTED = "0001-01-01"


class _UnixConnection(http.client.HTTPConnection):
    def __init__(self, path, timeout):
        super().__init__("localhost", timeout=timeout)
        self._path = path

    def connect(self):
        sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        try:
            sock.connect(self._path)
        except OSError:
            sock.close()
            raise
        self.sock = sock


def api_get(path, socket_path=SOCKET, timeout=util.DEFAULT_TIMEOUT, limit=LIMIT):
    conn = _UnixConnection(socket_path, timeout)
    try:
        conn.request("GET", path)
        response = conn.getresponse()
        body = response.read(limit + 1)
        if response.status != 200:
            raise util.CollectorError(f"API Docker {path} : HTTP {response.status}")
        if len(body) > limit:
            raise util.CollectorError(f"API Docker {path} : réponse trop volumineuse")
        return json.loads(body)
    except (OSError, http.client.HTTPException, ValueError) as exc:
        raise util.CollectorError(f"API Docker {path} : {exc}") from exc
    finally:
        conn.close()


def has_socket(path=SOCKET):
    try:
        return stat.S_ISSOCK(os.stat(path).st_mode)
    except OSError:
        return False


def _ports(entries):
    ports = []
    for entry in entries or []:
        if not entry.get("PublicPort"):
            continue  # exposed by the image, not published on the host
        port = {
            "address": entry.get("IP") or "0.0.0.0",
            "port": entry["PublicPort"],
            "container_port": entry.get("PrivatePort"),
            "protocol": entry.get("Type") or "tcp",
        }
        if port not in ports:
            ports.append(port)
    return ports


def _started_at(value):
    """'2026-10-20T08:12:03.123456789Z' -> '2026-10-20T08:12:03Z'; Docker's zero date -> None."""
    if not value or value.startswith(_NEVER_STARTED):
        return None
    return value[:19] + "Z"


def _container(summary, inspect):
    labels = summary.get("Labels") or {}
    state = inspect.get("State") or {}
    restart = ((inspect.get("HostConfig") or {}).get("RestartPolicy") or {}).get("Name")
    names = summary.get("Names") or []
    return {
        "id": summary["Id"][:12],
        "name": names[0].lstrip("/") if names else None,
        "image": summary.get("Image"),
        "image_version": labels.get("org.opencontainers.image.version"),
        "state": summary.get("State"),
        "ports": _ports(summary.get("Ports")),
        "compose_project": labels.get(_COMPOSE + "project"),
        "compose_service": labels.get(_COMPOSE + "service"),
        "compose_dir": labels.get(_COMPOSE + "project.working_dir"),
        "restart_policy": restart or None,
        "started_at": _started_at(state.get("StartedAt")),
    }


def collect_docker(api=api_get, has_docker=has_socket):
    if not has_docker():
        return None
    version = api("/version").get("Version")
    containers = []
    for summary in api("/containers/json?all=1"):
        if not _ID.match(summary.get("Id") or ""):
            continue
        try:
            inspect = api(f"/containers/{summary['Id']}/json")
        except util.CollectorError:
            inspect = {}  # removed between the list and this request
        containers.append(_container(summary, inspect))
    return {"version": version, "containers": containers}


def published_ports(api=api_get, has_docker=has_socket):
    """Host ports a running container publishes: a local ProxyPass to one reaches Docker."""
    if not has_docker():
        return set()
    try:
        running = api("/containers/json")
    except util.CollectorError:
        return set()
    return {port["port"] for summary in running for port in _ports(summary.get("Ports"))}
