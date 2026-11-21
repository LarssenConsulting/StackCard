"""Section `mounts`: local filesystems mounted right now (ext4, xfs, btrfs, vfat), from findmnt."""

import re

from discover_agent import util
from discover_agent.sections.core import Collected, read_source

FSTYPES = "ext4,xfs,btrfs,vfat"
COLUMNS = ("target", "source", "fstype", "options")
FINDMNT = ["/usr/bin/findmnt", "-rno", "TARGET,SOURCE,FSTYPE,OPTIONS", "-t", FSTYPES]

_ESCAPE = re.compile(rb"\\x([0-9a-fA-F]{2})")


def unescape(field):
    """findmnt -r writes spaces and unsafe bytes as \\xHH; give them back as text."""
    raw = _ESCAPE.sub(lambda m: bytes([int(m.group(1), 16)]), field.encode("utf-8", "surrogateescape"))
    return raw.decode("utf-8", "replace")


def parse(text):
    mounts = []
    for line in text.splitlines():
        if not line.strip():
            continue
        fields = line.split(" ")
        if len(fields) != len(COLUMNS):
            raise util.CollectorError(f"unexpected findmnt line: {line[:200]}")
        row = dict(zip(COLUMNS, (unescape(f) for f in fields)))
        row["options"] = row["options"].split(",") if row["options"] else []
        row["read_only"] = "ro" in row["options"]
        mounts.append(row)
    mounts.sort(key=lambda m: (m["target"], m["source"]))
    return mounts


def read_mounts(run_full):
    try:
        code, out, err = run_full(FINDMNT, env=util.c_locale_env())
    except util.CommandError as exc:
        if str(exc).startswith("command not found"):
            raise util.NotInstalled("findmnt") from exc
        raise
    # findmnt exits 1 when nothing matches; with a message on stderr it is a real failure.
    if code == 1 and not out.strip() and not err.strip():
        return []
    if code != 0:
        raise util.CollectorError(f"findmnt exited {code}: {err.strip()[:500]}")
    return parse(out)


def collect(run_full=util.run_full):
    errors = {}
    mounts = read_source(errors, "findmnt", lambda: read_mounts(run_full))
    return Collected({"filesystems": mounts}, errors)
