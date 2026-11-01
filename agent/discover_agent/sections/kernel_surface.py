"""Section `kernel_surface`: allow-listed sysctl values, loaded modules, modprobe overrides."""

import os
import re

from discover_agent import util
from discover_agent.redact import redact
from discover_agent.sections import defaults
from discover_agent.sections.core import Collected, read_source
from discover_agent.sections.tools import run_tool

_SYSCTL_NAME = re.compile(r"[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)*\Z")
_MODPROBE_LINE = re.compile(r"(?:blacklist|install)\b")
MODPROBE_DIRS = ("/etc/modprobe.d", "/lib/modprobe.d")


def read_sysctl(reader, names):
    values = {}
    for name in names:
        if not _SYSCTL_NAME.match(name):
            continue
        text = reader("/proc/sys/" + name.replace(".", "/"))
        values[name] = None if text is None else text.strip()
    return values


def parse_lsmod(text):
    lines = text.splitlines()[1:]
    return sorted({line.split()[0] for line in lines if line.split()})


def _filter_modprobe(text):
    return {redact(line.strip()) for line in text.splitlines() if _MODPROBE_LINE.match(line.strip())}


def read_modprobe(runner, reader, listdir):
    try:
        return sorted(_filter_modprobe(run_tool(runner, ["modprobe", "--showconfig"])))
    except (util.CommandError, util.NotInstalled):
        pass
    found, lines = False, set()
    for directory in MODPROBE_DIRS:
        names = listdir(directory)
        if names is None:
            continue
        found = True
        for name in sorted(names):
            if name.endswith(".conf"):
                lines |= _filter_modprobe(reader(f"{directory}/{name}") or "")
    if not found:
        raise util.CollectorError("modprobe --showconfig failed and /etc/modprobe.d is unreadable")
    return sorted(lines)


def _listdir(path):
    try:
        return os.listdir(path)
    except OSError:
        return None


def collect(runner=util.run_c, reader=util.read_text, listdir=_listdir, sysctl=defaults.SYSCTL):
    errors = {}
    data = {
        "sysctl": read_sysctl(reader, sysctl),
        "modules": read_source(errors, "modules", lambda: parse_lsmod(run_tool(runner, ["lsmod"]))),
        "modprobe": read_source(errors, "modprobe", lambda: read_modprobe(runner, reader, listdir)),
    }
    return Collected(data, errors)
