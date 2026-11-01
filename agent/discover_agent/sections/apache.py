"""Section `apache`: loaded modules, vhosts and a few allow-listed directives.

Scope: only `*.conf` files are scanned (not `.load` files, `envvars`...).
Only the directives of `defaults.APACHE_DIRECTIVES` leave the server, as file, line and
value (never a whole config file). Everything under /etc/apache2 is scanned, including
`*-available`; `enabled` tells whether Apache loads the file.
"""

import os
import re

from discover_agent import util
from discover_agent.collectors import apache as ctl
from discover_agent.redact import redact
from discover_agent.sections.core import Collected, describe, read_source
from discover_agent.sections.defaults import APACHE_DIRECTIVES
from discover_agent.sections.tools import run_tool

APACHE_ROOT = "/etc/apache2"
_CANONICAL = {name.lower(): name for name in APACHE_DIRECTIVES}


def walk_files(root, onerror=None):
    """Every file and symlink below `root`, sorted; links to directories are not followed."""
    found = []
    for base, dirs, names in os.walk(root, followlinks=False, onerror=onerror):
        dirs.sort()
        found += [os.path.join(base, n) for n in names]
        found += [os.path.join(base, d) for d in dirs if os.path.islink(os.path.join(base, d))]
    return sorted(found)


def _section_of(path):
    """('sites', True) for sites-available/x.conf; ('sites', False) for sites-enabled; else None."""
    parts = path[len(APACHE_ROOT) + 1:].split("/")
    if len(parts) < 2:
        return None
    for suffix, available in (("-available", True), ("-enabled", False)):
        if parts[0].endswith(suffix):
            return parts[0][: -len(suffix)], available
    return None


def parse_directives(text):
    """(line, directive, value) for the allow-listed directives; comments are skipped."""
    found = []
    for number, line in ctl._logical_lines(text):
        if not line or line.startswith("#") or line.startswith("<"):
            continue
        parts = line.split(None, 1)
        name = _CANONICAL.get(parts[0].lower())
        if name:
            found.append((number, name, parts[1].strip() if len(parts) > 1 else ""))
    return found


_CTL_S_LINE = re.compile(
    r"(?:port (?P<port>\d+) namevhost|^\S*:(?P<port2>\d+))\s+(?P<name>\S+)\s+\((?P<file>/[^():]+):(?P<line>\d+)\)"
)


def _ctl_s_entries(output):
    """(file, line) -> (name, port) as `apache2ctl -S` prints them; the fallback for unparsed blocks."""
    found = {}
    for raw in output.splitlines():
        match = _CTL_S_LINE.search(raw.strip())
        if match:
            key = (match.group("file"), int(match.group("line")))
            found.setdefault(key, (match.group("name"), int(match.group("port") or match.group("port2"))))
    return found


def _vhosts(output, reader, errors):
    parsed, vhosts = {}, []
    printed = _ctl_s_entries(output)
    for path, line in ctl.parse_ctl_s(output):
        if path not in parsed:
            text = reader(path)
            if text is None:
                errors[f"read {path}"] = "unreadable"
            parsed[path] = dict(ctl.parse_vhosts_text(text or ""))
        block = parsed[path].get(line)
        if block is not None:
            vhosts.append({"server_name": block["server_name"], "port": block["port"], "file": path, "line": line})
        elif (path, line) in printed:  # no matching <VirtualHost> in the file: keep what -S says
            name, port = printed[(path, line)]
            vhosts.append({"server_name": name, "port": port, "file": path, "line": line})
    for macro in ctl.parse_ctl_s_macros(output):
        vhosts.append({"server_name": macro["server_name"], "port": macro["port"],
                       "file": macro["config_file"], "line": None})
    return sorted(vhosts, key=lambda v: (v["server_name"] or "", v["port"] or 0, v["file"], v["line"] or 0))


def _emit(result, path, text, enabled):
    for number, name, value in parse_directives(text):
        result.append({"file": path, "line": number, "directive": name,
                       "value": redact(value), "enabled": enabled})


def _directives(reader, walker, islink, resolve, read_target, errors):
    def onerror(exc):
        errors[f"walk {getattr(exc, 'filename', None) or APACHE_ROOT}"] = describe(exc)

    files = [p for p in walker(APACHE_ROOT, onerror) if p.endswith(".conf")]
    known = set(files)
    enabled_targets, outside = set(), set()
    for path in files:
        kind = _section_of(path)
        if kind and not kind[1] and islink(path):
            target = resolve(path)
            enabled_targets.add(target)
            if target not in known:
                outside.add(target)
    result = []
    for path in files:
        kind = _section_of(path)
        if kind and not kind[1] and islink(path):
            continue  # the target is read on its own, once
        text = reader(path)
        if text is None:
            errors[f"read {path}"] = "unreadable"
            continue
        _emit(result, path, text, not (kind and kind[1]) or resolve(path) in enabled_targets)
    for target in sorted(outside):
        # What Apache loads through a link that leaves /etc/apache2; the owner may be anyone.
        text = read_target(target)
        if text is None:
            errors[f"read {target}"] = "unreadable or dangling link target"
            continue
        _emit(result, target, text, True)
    return sorted(result, key=lambda d: (d["file"], d["line"], d["directive"]))


def collect(runner=util.run, reader=util.read_regular_file, walker=walk_files, islink=os.path.islink,
            resolve=os.path.realpath, read_target=util.read_regular_file):
    errors = {}
    # apache2ctl absent: NotInstalled for the whole section.
    modules_text = None
    try:
        modules_text = run_tool(runner, ["apache2ctl", "-M"])
    except util.NotInstalled:
        raise
    except Exception as exc:
        errors["apache2ctl -M"] = describe(exc)
    modules = None if modules_text is None else sorted(ctl.parse_ctl_m(modules_text))

    vhosts = None
    output = read_source(errors, "apache2ctl -S", lambda: run_tool(runner, ["apache2ctl", "-S"]))
    if output is not None:
        vhosts = _vhosts(output, reader, errors)
    directives = _directives(reader, walker, islink, resolve, read_target, errors)
    return Collected({"modules": modules, "vhosts": vhosts, "directives": directives}, errors)
