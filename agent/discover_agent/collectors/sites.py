"""Turn a parsed vhost into a site: its type, system user and application dir."""

import glob
import grp
import os
import pwd
import re
from dataclasses import dataclass
from datetime import datetime, timezone
from typing import Callable
from urllib.parse import urlsplit

from discover_agent import util

LOCAL_HOSTS = {"127.0.0.1", "localhost", "::1", "[::1]", "0.0.0.0"}
POOL_GLOB = "/etc/php/*/fpm/pool.d/*.conf"

_PHP_VERSION = re.compile(r"/etc/php/(\d+\.\d+)/")
_SS_PID = re.compile(r"pid=(\d+)")


@dataclass
class SiteContext:
    fpm_pools: dict
    listeners: dict
    php_module: bool
    proc_info: Callable
    owner: Callable
    cert_expiry: Callable


def normalize_socket(path):
    """/var/run is a symlink to /run on Ubuntu: configs use both spellings."""
    return "/run/" + path[len("/var/run/"):] if path.startswith("/var/run/") else path


def strip_userinfo(url):
    """Drop user:password@ from a URL: credentials never leave the server.

    Cut at the last '@' rather than trusting urlsplit, which ends the authority
    at the first '/', '?' or '#' of an unencoded password and would keep the
    rest of it. Losing an '@' in a proxy path is the price of never leaking.
    """
    scheme, sep, rest = url.partition("://")
    if not sep or "@" not in rest:
        return url
    return f"{scheme}://{rest.rpartition('@')[2]}"


def parse_fpm_pools(files):
    """files: {path: text} -> {listen address: {user, group, php_version}}"""
    pools = {}
    for path, text in files.items():
        version_match = _PHP_VERSION.search(path)
        version = version_match.group(1) if version_match else None
        name, values = None, {}

        def flush():
            listen = values.get("listen")
            if name and listen:
                pools[normalize_socket(listen.replace("$pool", name))] = {
                    "user": values.get("user"),
                    "group": values.get("group"),
                    "php_version": version,
                }

        for raw in text.splitlines():
            line = raw.strip()
            if not line or line.startswith(";"):
                continue
            if line.startswith("[") and line.endswith("]"):
                flush()
                name, values = line[1:-1], {}
                continue
            key, sep, value = line.partition("=")
            if sep:
                values[key.strip()] = value.strip().strip('"')
        flush()
    return pools


def parse_ss(text):
    listeners = {}
    for line in text.splitlines():
        fields = line.split()
        if len(fields) < 4:
            continue
        port = fields[3].rsplit(":", 1)[-1]
        pid = _SS_PID.search(line)
        if port.isdigit() and pid and int(port) not in listeners:
            listeners[int(port)] = int(pid.group(1))
    return listeners


def parse_cert_enddate(text):
    value = text.strip().split("=", 1)[-1]
    parsed = datetime.strptime(" ".join(value.split()), "%b %d %H:%M:%S %Y %Z")
    return util.iso_utc(parsed.replace(tzinfo=timezone.utc))


def process_manager_from(cgroup_text, ancestor_names):
    if any(name.startswith("PM2") for name in ancestor_names):
        return "pm2"
    match = re.search(r"/([^/\s]+\.service)\s*$", cgroup_text.strip().splitlines()[-1] if cgroup_text.strip() else "")
    return f"systemd:{match.group(1)}" if match else "unknown"


def _empty_site(vhost):
    return {
        "server_name": vhost["server_name"],
        "aliases": vhost["aliases"],
        "port": vhost["port"],
        "https": vhost["ssl"],
        "cert_expires_at": None,
        "type": "static",
        "document_root": vhost["document_root"],
        "app_dir": None,
        "upstream": None,
        "php_version": None,
        "system_user": None,
        "system_group": None,
        "owner_user": None,
        "owner_group": None,
        "process_manager": None,
        "config_file": vhost["config_file"],
        "partial": vhost["partial"],
    }


def classify(vhost, ctx):
    site = _empty_site(vhost)
    if vhost["ssl"] and vhost["cert_file"]:
        site["cert_expires_at"] = ctx.cert_expiry(vhost["cert_file"])

    if vhost["proxy_pass"]:
        site["upstream"] = strip_userinfo(vhost["proxy_pass"])
        target = urlsplit(site["upstream"])
        if target.scheme in ("http", "https") and target.hostname in LOCAL_HOSTS and target.port:
            site["type"] = "node"
            pid = ctx.listeners.get(target.port)
            info = ctx.proc_info(pid) if pid else None
            if info:
                site["system_user"], site["system_group"] = info["user"], info["group"]
                site["app_dir"] = info["cwd"]
                site["process_manager"] = info["process_manager"]
        else:
            site["type"] = "proxy"
    elif vhost["fpm_socket"]:
        site["type"] = "php-fpm"
        site["app_dir"] = vhost["document_root"]
        pool = ctx.fpm_pools.get(normalize_socket(vhost["fpm_socket"]))
        if pool:
            site["system_user"], site["system_group"] = pool["user"], pool["group"]
            site["php_version"] = pool["php_version"]
    elif ctx.php_module and vhost["document_root"]:
        site["type"] = "php-mod"
        site["app_dir"] = vhost["document_root"]
        site["system_user"], site["system_group"] = vhost["assign_user"] or ("www-data", "www-data")
    else:
        site["app_dir"] = vhost["document_root"]

    if site["app_dir"]:
        site["owner_user"], site["owner_group"] = ctx.owner(site["app_dir"])
    if site["type"] == "static":
        site["system_user"], site["system_group"] = site["owner_user"], site["owner_group"]
    return site


# --- Real system lookups -------------------------------------------------


def _name_of(lookup, ident):
    try:
        return lookup(ident)[0]
    except KeyError:
        return str(ident)


def owner_of(path):
    try:
        st = os.stat(path)
    except OSError:
        return None, None
    return _name_of(pwd.getpwuid, st.st_uid), _name_of(grp.getgrgid, st.st_gid)


def _status_field(pid, field):
    text = util.read_text(f"/proc/{pid}/status") or ""
    for line in text.splitlines():
        if line.startswith(field + ":"):
            return line.split()[1]
    return None


def proc_info(pid):
    uid, gid = _status_field(pid, "Uid"), _status_field(pid, "Gid")
    if uid is None:
        return None
    try:
        cwd = os.readlink(f"/proc/{pid}/cwd")
    except OSError:
        cwd = None
    ancestors, current = [], pid
    for _ in range(32):
        current = _status_field(current, "PPid")
        if not current or current == "0":
            break
        ancestors.append((util.read_text(f"/proc/{current}/comm") or "").strip())
    return {
        "user": _name_of(pwd.getpwuid, int(uid)),
        "group": _name_of(grp.getgrgid, int(gid)),
        "cwd": cwd,
        "process_manager": process_manager_from(util.read_text(f"/proc/{pid}/cgroup") or "", ancestors),
    }


def cert_expiry(path, runner=util.run):
    try:
        return parse_cert_enddate(runner(["openssl", "x509", "-enddate", "-noout", "-in", path]))
    except (util.CommandError, ValueError):
        return None


def build_context(runner=util.run):
    pool_files = {path: util.read_text(path) or "" for path in glob.glob(POOL_GLOB)}
    try:
        listeners = parse_ss(runner(["ss", "-Hltnp"]))
    except util.CommandError:
        listeners = {}
    try:
        php_module = bool(re.search(r"^\s*php\S*_module", runner(["apache2ctl", "-M"]), re.MULTILINE))
    except util.CommandError:
        php_module = False
    return SiteContext(
        fpm_pools=parse_fpm_pools(pool_files),
        listeners=listeners,
        php_module=php_module,
        proc_info=proc_info,
        owner=owner_of,
        cert_expiry=lambda path: cert_expiry(path, runner),
    )
