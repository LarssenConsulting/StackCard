"""Section `code_patterns`: StackCard-supplied patterns searched in the DocumentRoots.

Site files are controlled by the site owners and read as root, so:
  * only a path, a line number and a pattern id ever leave the server, never a line;
  * files are read through `util.read_regular_file` (regular files <= 1 MiB);
  * symlinks are never followed, except a DocumentRoot that is itself a link (resolved once);
  * a file holding a line over 64 KiB is not analysed (bounded regex time) and counts as skipped;
  * a path that is not valid UTF-8 would break serialisation: not emitted, counted as skipped;
  * the scan stops at a deadline or at 10000 matches and says so (`truncated`, `reason`).
`skipped_files` counts the candidate files not (fully) analysed.
"""

import fnmatch
import os
import re
import time

from discover_agent import util
from discover_agent.collectors import apache as ctl
from discover_agent.sections.core import Collected
from discover_agent.sections.defaults import CODE_PATTERNS
from discover_agent.sections.tools import run_tool

MAX_MATCHES = 10000
MAX_LINE = 64 * 1024
DEADLINE = 270  # seconds; the section timeout is 300 s


def _printable(path):
    return not any(ord(c) < 32 or ord(c) == 127 for c in path)


def _encodable(path):
    """False for a name with bytes that are not UTF-8 (os.walk gives lone surrogates)."""
    try:
        path.encode("utf-8")
    except UnicodeEncodeError:
        return False
    return True


def _compile(patterns):
    return [(p["id"], re.compile(p["regex"]), tuple(p["globs"])) for p in patterns]


def collect(runner=util.run, reader=util.read_regular_file, vhosts=ctl.load_vhosts, patterns=None,
            read_file=util.read_regular_file, clock=time.monotonic, deadline=DEADLINE):
    compiled = _compile(CODE_PATTERNS if patterns is None else patterns)

    def tool_runner(cmd, **kwargs):
        return run_tool(runner, cmd, **kwargs)

    roots = []
    for vhost in vhosts(runner=tool_runner, reader=reader):
        root = vhost.get("document_root")
        if root and root not in roots:
            roots.append(root)

    started = clock()
    matches = []
    state = {"skipped": 0, "truncated": False, "reason": None}
    missing = []

    def stop(reason):
        state["truncated"], state["reason"] = True, reason

    def scan_file(path, applicable):
        if not _encodable(path) or not _printable(path) or os.path.islink(path):
            state["skipped"] += 1
            return
        text = read_file(path)
        if text is None or any(len(line) > MAX_LINE for line in text.split("\n")):
            state["skipped"] += 1
            return
        found = set()
        for pid, regex, _ in applicable:
            # Whole text, so `\s*` can cross a newline; the line is derived from the offset.
            line, last = 1, 0
            for match in regex.finditer(text):
                line += text.count("\n", last, match.start())
                last = match.start()
                found.add((line, pid))
        for line, pid in sorted(found):
            if len(matches) >= MAX_MATCHES:
                stop("max_matches")
                return
            matches.append({"path": path, "line": line, "pattern": pid})

    def unreadable(error):
        state["skipped"] += 1

    real_roots = []
    for root in roots:
        if not _encodable(root):
            state["skipped"] += 1
            continue
        real = os.path.realpath(root)
        if not os.path.isdir(real):
            missing.append(root)
        elif real not in real_roots:
            real_roots.append(real)
    # Parents first, so a root inside an already scanned one is skipped.
    scanned = []
    for real in sorted(real_roots):
        if any(real == prev or real.startswith(prev.rstrip(os.sep) + os.sep) for prev in scanned):
            continue
        scanned.append(real)
        for base, dirs, names in os.walk(real, followlinks=False, onerror=unreadable):
            dirs.sort()
            for name in sorted(names):
                if state["truncated"]:
                    break
                if clock() - started > deadline:
                    stop("deadline")
                    break
                applicable = [p for p in compiled if any(fnmatch.fnmatchcase(name, g) for g in p[2])]
                if applicable:
                    path = os.path.join(base, name)
                    if os.path.islink(path):
                        continue  # never followed, and not a candidate
                    scan_file(path, applicable)
            if state["truncated"]:
                break
        if state["truncated"]:
            break

    matches.sort(key=lambda m: (m["path"], m["line"], m["pattern"]))
    data = {
        "matches": matches,
        "skipped_files": state["skipped"],
        "missing_roots": sorted(missing),
        "truncated": state["truncated"],
    }
    if state["truncated"]:
        data["reason"] = state["reason"]
    return Collected(data)
