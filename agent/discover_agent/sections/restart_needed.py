"""Section `restart_needed`: services running on outdated libraries, master process start times."""

import os
from datetime import datetime, timezone

from discover_agent import util
from discover_agent.sections.core import Collected, read_source
from discover_agent.sections.tools import SLOW_TIMEOUT, run_tool

MASTER_PREFIXES = ("php-fpm", "apache2")


class ProcFS:
    """Read-only view of /proc; tests replace it with a fake exposing `pids`, `read`, `btime`."""

    def __init__(self, root="/proc", clk_tck=None):
        self.root = root
        self.clk_tck = clk_tck or os.sysconf("SC_CLK_TCK")

    def pids(self):
        try:
            return sorted(int(n) for n in os.listdir(self.root) if n.isdigit())
        except OSError:
            return []

    def read(self, pid, name):
        try:
            with open(f"{self.root}/{pid}/{name}", encoding="utf-8", errors="replace") as fh:
                return fh.read()
        except OSError:
            return None

    @property
    def btime(self):
        try:
            with open(f"{self.root}/stat", encoding="utf-8") as fh:
                for line in fh:
                    if line.startswith("btime "):
                        return int(line.split()[1])
        except (OSError, ValueError):
            pass
        return None


def _stat(proc, pid):
    """(comm, ppid, starttime ticks) from /proc/<pid>/stat, or None."""
    text = proc.read(pid, "stat")
    if not text:
        return None
    left, right = text.find("("), text.rfind(")")
    if left < 0 or right < left:
        return None
    rest = text[right + 1:].split()
    try:
        return text[left + 1:right], int(rest[1]), int(rest[19])
    except (IndexError, ValueError):
        return None


def master_pids(comm_prefix, proc_reader):
    """Master processes whose command name starts with `comm_prefix`, with their UTC start time.

    A master is a matching process whose parent does not match (workers share the
    master's name). `proc_reader` exposes `pids()`, `read(pid, name)` and `btime`;
    `ProcFS` is the real one.
    """
    stats = {}
    for pid in proc_reader.pids():
        info = _stat(proc_reader, pid)
        if info:
            stats[pid] = info
    btime = proc_reader.btime
    tck = getattr(proc_reader, "clk_tck", 100)
    masters = []
    for pid, (comm, ppid, ticks) in stats.items():
        if not comm.startswith(comm_prefix):
            continue
        parent = stats.get(ppid)
        if parent is None and ppid != 0:
            continue  # parent unreadable: cannot tell a master from a worker
        if parent and parent[0].startswith(comm_prefix):
            continue
        started = None
        if btime is not None:
            started = util.iso_utc(datetime.fromtimestamp(btime + ticks // tck, timezone.utc))
        masters.append({"comm": comm, "pid": pid, "started_at": started})
    masters.sort(key=lambda m: (m["comm"], m["pid"]))
    return masters


def parse_needrestart(text):
    values = {"KCUR": None, "KEXP": None, "KSTA": None}
    services = set()
    for line in text.splitlines():
        key, _, value = line.partition(":")
        key, value = key.strip(), value.strip()
        if key == "NEEDRESTART-SVC" and value:
            services.add(value)
        elif key.startswith("NEEDRESTART-") and key[12:] in values:
            values[key[12:]] = value
    status = values["KSTA"]
    return {
        "kernel_current": values["KCUR"], "kernel_expected": values["KEXP"],
        "kernel_status": int(status) if status and status.isdigit() else None,
        "services": sorted(services),
    }


def collect(runner=util.run_c, proc=None):
    errors = {}
    proc = proc or ProcFS()
    needrestart = read_source(
        errors, "needrestart", lambda: parse_needrestart(run_tool(runner, ["needrestart", "-b", "-r", "l"], timeout=SLOW_TIMEOUT))
    )
    masters = []
    for prefix in MASTER_PREFIXES:
        masters += master_pids(prefix, proc)
    masters.sort(key=lambda m: (m["comm"], m["pid"]))
    return Collected({"needrestart": needrestart, "masters": masters}, errors)
