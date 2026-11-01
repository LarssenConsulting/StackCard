"""Section `apt_sources`: active repositories, origin of key packages, Ubuntu Pro state."""

import fnmatch
import json
import os
import re

from discover_agent import util
from discover_agent.redact import redact
from discover_agent.sections import packages
from discover_agent.sections.core import Collected, read_source
from discover_agent.sections.tools import run_tool

APT_DIR = "/etc/apt"
KEY_PACKAGES = ("php*", "nodejs", "apache2", "libbrotli1", "linux-image-*", "docker-ce")
_OPTIONS = re.compile(r"\[[^\]]*\]")
_ORIGIN_LINE = re.compile(r"\s+\d+\s+(\S+)\s+(\S+)")
PRO_TIMEOUT = 10  # the section has 30 s in all; a slow `pro` must not cost the sources


def _entry(path, uri, suite, components):
    return {"file": path, "uri": redact(uri), "suite": suite, "components": sorted(components)}


def parse_list(path, text):
    """One-line format. Options (`[signed-by=...]`) are dropped before anything is read."""
    entries = []
    for line in text.splitlines():
        words = _OPTIONS.sub(" ", line.split("#", 1)[0]).split()
        if len(words) >= 3 and words[0] == "deb":
            entries.append(_entry(path, words[1], words[2], words[3:]))
    return entries


def parse_sources(path, text):
    """deb822 format: blocks separated by blank lines, `Enabled: no` skipped, keys other than the four ignored."""
    entries = []
    for block in re.split(r"\n\s*\n", text):
        fields = {}
        last = None
        for line in block.splitlines():
            if line.startswith("#"):
                continue
            if line[:1].isspace():  # folded value: continues the previous field
                if last is not None and line.strip() != ".":
                    fields[last] += " " + line.strip()
                continue
            if ":" not in line:
                continue
            key, value = line.split(":", 1)
            last = key.strip().lower()
            fields[last] = value.strip()
        if fields.get("enabled", "yes").lower() in ("no", "false", "0"):
            continue
        if "deb" not in fields.get("types", "").split():
            continue
        components = fields.get("components", "").split()
        for uri in fields.get("uris", "").split():
            for suite in fields.get("suites", "").split():
                entries.append(_entry(path, uri, suite, components))
    return entries


def read_sources(reader, listdir):
    paths = [f"{APT_DIR}/sources.list"]
    names = listdir(f"{APT_DIR}/sources.list.d") or []
    paths += [f"{APT_DIR}/sources.list.d/{n}" for n in sorted(names) if n.endswith((".list", ".sources"))]
    entries = []
    for path in paths:
        text = reader(path)
        if text is None:
            continue
        entries += (parse_sources if path.endswith(".sources") else parse_list)(path, text)
    entries.sort(key=lambda e: (e["file"], e["uri"], e["suite"], e["components"]))
    return entries


def parse_policy(text, wanted):
    """Origin (uri + suite/component) of the installed version of each package in `wanted`."""
    results = {}
    current = None
    in_installed = False
    for line in text.splitlines():
        if line and not line[0].isspace() and line.endswith(":"):
            name = line[:-1]
            current = {"package": name, "version": None, "origins": []} if name in wanted else None
            in_installed = False
            if current:
                results[name] = current
            continue
        if current is None:
            continue
        stripped = line.strip()
        if stripped.startswith("Installed:"):
            value = stripped.split(":", 1)[1].strip()
            current["version"] = None if value == "(none)" else value
        elif stripped.startswith("***"):
            in_installed = True
        elif len(stripped.split()) == 2 and stripped.split()[1].isdigit():
            in_installed = False  # another version of the table
        elif in_installed:
            m = _ORIGIN_LINE.match(line)
            if m and m.group(1).startswith(("http://", "https://", "ftp://", "file:", "cdrom:")):
                current["origins"].append({"uri": redact(m.group(1)), "suite": m.group(2)})
    for entry in results.values():
        entry["origins"].sort(key=lambda o: (o["uri"], o["suite"]))
    return [results[name] for name in sorted(results)]


def read_origins(runner, rows):
    names = sorted({r["package"] for r in rows if any(fnmatch.fnmatchcase(r["package"], p) for p in KEY_PACKAGES)})
    if not names:
        return []
    return parse_policy(run_tool(runner, ["apt-cache", "policy", *names]), set(names))


def read_pro(runner):
    data = json.loads(run_tool(runner, ["pro", "status", "--format", "json"], timeout=PRO_TIMEOUT))
    if not isinstance(data, dict) or not isinstance(data.get("attached"), bool):
        raise util.CollectorError("unexpected pro status output")
    services = []
    for svc in data.get("services") or []:
        if not isinstance(svc, dict) or not isinstance(svc.get("name"), str):
            continue
        # An attached machine reports `status`; an unattached one only `available`.
        state = svc.get("status") if isinstance(svc.get("status"), str) else svc.get("available")
        if isinstance(state, str):
            services.append({"name": svc["name"], "status": state})
    services.sort(key=lambda s: s["name"])
    return {"attached": data["attached"], "services": services}


def collect(runner=util.run_c, reader=util.read_text, listdir=None):
    listdir = listdir or _listdir
    errors = {}
    sources = read_source(errors, "sources", lambda: read_sources(reader, listdir))
    origins = read_source(errors, "origins", lambda: read_origins(runner, packages.installed_packages(runner)))
    pro = read_source(errors, "pro", lambda: read_pro(runner))
    return Collected({"sources": sources, "origins": origins, "pro": pro}, errors)


def _listdir(path):
    try:
        return os.listdir(path)
    except OSError:
        return None
