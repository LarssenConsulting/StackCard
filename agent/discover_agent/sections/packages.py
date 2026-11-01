"""Section `packages`: installed dpkg packages with their source package."""

import os
from datetime import datetime, timezone

from discover_agent import util
from discover_agent.sections.core import Collected
from discover_agent.sections.tools import run_tool

COMMAND = [
    "dpkg-query", "-W",
    "--showformat=${Package}\\t${Version}\\t${Architecture}\\t${source:Package}\\t${source:Version}\\t${db:Status-Abbrev}\\n",
]
INFO_DIR = "/var/lib/dpkg/info"
KEPT = ("ii", "hi")


def parse_dpkg(text):
    """Rows of the dpkg-query output for `ii` and `hi` packages, sorted by (package, arch)."""
    rows = []
    for line in text.splitlines():
        parts = line.split("\t")
        if len(parts) != 6:
            continue
        package, version, arch, source, source_version, status = (p.strip() for p in parts)
        if status not in KEPT or not package:
            continue
        rows.append({
            "package": package, "version": version, "arch": arch,
            "source_package": source or package, "source_version": source_version or version,
            "status": status,
        })
    rows.sort(key=lambda r: (r["package"], r["arch"]))
    return rows


def installed_packages(runner=util.run_c):
    """Parsed `dpkg-query` output; shared with the sections that need the package list."""
    return parse_dpkg(run_tool(runner, COMMAND))


def _mtime(path):
    try:
        return os.stat(path).st_mtime
    except OSError:
        return None


def collect(runner=util.run_c, mtime=_mtime):
    rows = installed_packages(runner)
    for row in rows:
        stamp = None
        for name in (f"{row['package']}:{row['arch']}.list", f"{row['package']}.list"):
            stamp = mtime(f"{INFO_DIR}/{name}")
            if stamp is not None:
                break
        row["installed_at"] = None if stamp is None else util.iso_utc(datetime.fromtimestamp(stamp, timezone.utc))
    return Collected(rows)
