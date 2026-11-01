"""Section `site_dependencies`: Composer packages and known applications, per application directory.

composer.lock belongs to the site owner: it is read with `util.read_regular_file`
(never `open`), parsed as data, and only name, version and the dev flag are kept.
An absent lock is `[]` (nothing to read); an oversized, unreadable or malformed
one is `None` plus a `source_errors` entry that never quotes the file.
"""

import dataclasses
import json
import os
import re

from discover_agent import util
from discover_agent.collectors import apache as ctl
from discover_agent.collectors import sites as sites_collector
from discover_agent.collectors import techs
from discover_agent.sections.core import Collected, describe, read_source
from discover_agent.sections.tools import run_tool

PHP_TYPES = ("php-fpm", "php-mod", "static")
PHPMYADMIN_PACKAGE = "phpmyadmin/phpmyadmin"
PHPMYADMIN_VERSION_FILE = "libraries/classes/Version.php"
_PHPMYADMIN_VERSION = re.compile(r"const\s+VERSION\s*=\s*'([^']+)'")
# dev-main, v1.2.3, 1.2.3-beta+x: no ":", "/" or "@", so a URL with credentials never matches.
_VERSION_TEXT = re.compile(r"^[0-9A-Za-z.+_\-]{1,64}$")


def read_lock(path, reader, exists):
    """('absent'|'ok'|'error', data or message). Never raises, never quotes the file."""
    text = reader(path, techs.LOCK_LIMIT)
    if text is None:
        if exists(path):
            return "error", "illisible ou trop volumineux"
        return "absent", None
    try:
        data = json.loads(text)
    except (ValueError, RecursionError):
        return "error", "JSON invalide"
    if not isinstance(data, dict):
        return "error", "JSON invalide"
    return "ok", data


def _cached(reader):
    """Each path is read once per run, whoever asks (detection re-reads lock files)."""
    cache = {}

    def read(path, limit=1024 * 1024):
        key = (path, limit)
        if key not in cache:
            cache[key] = reader(path, limit)
        return cache[key]

    return read


def _version(value):
    return value if isinstance(value, str) and _VERSION_TEXT.match(value) else None


def _composer(lock):
    packages = []
    for key, dev in (("packages", False), ("packages-dev", True)):
        entries = lock.get(key)
        for entry in entries if isinstance(entries, list) else []:
            name = entry.get("name") if isinstance(entry, dict) else None
            if isinstance(name, str) and techs._PACKAGE_NAME.match(name):
                packages.append({"name": name, "version": _version(entry.get("version")), "dev": dev})
    return sorted(packages, key=lambda p: (p["name"], p["dev"]))


def _lock_dir(app_dir, exists):
    """The application directory that holds composer.lock: Laravel serves public/, the lock is above."""
    directory = os.path.abspath(app_dir)
    first = directory
    for _ in range(techs.PARENT_LEVELS + 1):
        if exists(os.path.join(directory, "composer.lock")):
            return directory
        parent = os.path.dirname(directory)
        if parent == directory:
            break
        directory = parent
    return first


def _applications(app_dir, packages, reader, detect):
    found = {}
    for tech in detect(app_dir, reader=reader):
        if tech["name"] == "WordPress" and tech.get("version"):
            found["WordPress"] = tech["version"]
    version = next((p["version"] for p in packages or [] if p["name"] == PHPMYADMIN_PACKAGE and p["version"]), None)
    if version is None:
        text = reader(os.path.join(app_dir, PHPMYADMIN_VERSION_FILE))
        match = _PHPMYADMIN_VERSION.search(text) if text else None
        version = _version(match.group(1)) if match else None
    if version:
        found["phpMyAdmin"] = version
    return [{"name": name, "version": found[name]} for name in ("WordPress", "phpMyAdmin") if name in found]


def _default_sites(runner, errors):
    def tool(cmd, **kwargs):
        return run_tool(runner, cmd, **kwargs)

    vhosts = ctl.load_vhosts(runner=tool)  # NotInstalled when apache2ctl is absent
    context = dataclasses.replace(sites_collector.build_context(runner=tool), cert_expiry=lambda path: None)
    classified = []
    for index, vhost in enumerate(vhosts):
        try:
            classified.append(sites_collector.classify(vhost, context))
        except Exception as exc:  # one hostile vhost must not hide the others
            errors[f"vhost {index}"] = describe(exc)
    return classified


def collect(runner=util.run, load_sites=None, reader=util.read_regular_file, exists=os.path.lexists,
            detect=techs.detect_technologies):
    errors = {}
    sites = (load_sites or (lambda: _default_sites(runner, errors)))()
    reader = _cached(reader)
    by_dir = {}
    for site in sites:
        app_dir = site.get("app_dir")
        if app_dir and site.get("type") in PHP_TYPES:
            entry = by_dir.setdefault(app_dir, [])
            item = {"server_name": site.get("server_name"), "port": site.get("port")}
            if item not in entry:
                entry.append(item)
    result = []
    for app_dir in sorted(by_dir):
        lock_path = os.path.join(_lock_dir(app_dir, exists), "composer.lock")
        status, data = read_lock(lock_path, reader, exists)
        packages = None
        if status == "absent":
            packages = []
        elif status == "ok":
            packages = _composer(data)
        else:
            errors[f"composer.lock {app_dir}"] = data
        applications = read_source(errors, f"applications {app_dir}",
                                   lambda: _applications(app_dir, packages, reader, detect)) or []
        result.append({"app_dir": app_dir,
                       "sites": sorted(by_dir[app_dir], key=lambda s: (s["server_name"] or "", s["port"] or 0)), "composer": packages,
                       "applications": applications})
    return Collected(result, errors)
