"""Section `kernel`: running and installed kernels, reboot state, boot time, livepatch."""

import json
import re
from datetime import datetime, timezone

from discover_agent import util
from discover_agent.sections import packages
from discover_agent.sections.core import Collected, read_source
from discover_agent.sections.tools import run_tool

_IMAGE = re.compile(r"linux-image-(?:unsigned-)?(\d.*)\Z")


def installed_kernels(rows):
    return sorted({m.group(1) for r in rows if (m := _IMAGE.match(r["package"]))})


def read_boot_time(reader):
    for line in (reader("/proc/stat") or "").splitlines():
        if line.startswith("btime "):
            return util.iso_utc(datetime.fromtimestamp(int(line.split()[1]), timezone.utc))
    raise util.CollectorError("btime not found in /proc/stat")


def _text(value):
    return value if isinstance(value, str) else None


def _flag(value):
    return value if isinstance(value, bool) else None


def reduce_livepatch(text):
    """Keep a fixed set of state fields; machine id, token and descriptions never leave."""
    data = json.loads(text)
    entries = data.get("Status") if isinstance(data, dict) else None
    entries = [e for e in entries if isinstance(e, dict)] if isinstance(entries, list) else []
    if not entries:
        raise util.CollectorError("unexpected canonical-livepatch output")
    entry = next((e for e in entries if e.get("Running") is True), entries[0])
    patch = entry.get("Livepatch") if isinstance(entry.get("Livepatch"), dict) else {}
    fixes = [
        {"name": f["Name"], "patched": _flag(f.get("Patched"))}
        for f in patch.get("Fixes") or []
        if isinstance(f, dict) and isinstance(f.get("Name"), str)
    ]
    fixes.sort(key=lambda f: f["name"])
    return {
        "kernel": _text(entry.get("Kernel")), "running": _flag(entry.get("Running")),
        "check_state": _text(patch.get("CheckState")), "patch_state": _text(patch.get("State")),
        "version": _text(patch.get("Version")), "fixes": fixes,
    }


def _pkgs(text):
    return sorted({line.strip() for line in text.splitlines() if line.strip()})


def read_optional(path):
    """File content, or None only when it does not exist; any other error propagates."""
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except FileNotFoundError:
        return None


def collect(runner=util.run_c, reader=read_optional):
    errors = {}
    running = read_source(errors, "running", lambda: run_tool(runner, ["uname", "-r"]).strip())
    installed = read_source(errors, "installed", lambda: installed_kernels(packages.installed_packages(runner)))
    flag = read_source(errors, "reboot_required", lambda: reader("/var/run/reboot-required") is not None)
    pkgs = read_source(
        errors, "reboot_required_pkgs", lambda: _pkgs(reader("/var/run/reboot-required.pkgs") or "")
    )
    data = {
        "running": running,
        "installed": installed,
        "reboot_required": flag,
        "reboot_required_pkgs": pkgs,
        "boot_time": read_source(errors, "boot_time", lambda: read_boot_time(reader)),
        "livepatch": read_source(
            errors, "livepatch",
            lambda: reduce_livepatch(run_tool(runner, ["canonical-livepatch", "status", "--format", "json"])),
        ),
    }
    return Collected(data, errors)
