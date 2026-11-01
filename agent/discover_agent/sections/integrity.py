"""Section `integrity`: files of installed packages that no longer match their checksums.

`debsums -c` prints one modified path per line and each missing file on stderr. Its exit
status is a combination: 1 when a package or file is not found, 2 when files changed or are
missing (container images drop docs), 3 for both. Those are results as long as there is
something to read; 1 or 3 with nothing at all, or anything above 3, means it did not check.

`ProtectKernelModules=yes` hides /lib/modules from the service, so every module reads as
missing rather than modified: `modules_checked` says whether they were really compared.
The owning package comes from `dpkg-query -S`; a path nobody owns has `package: null`.
"""

import os

from discover_agent import util
from discover_agent.redact import redact
from discover_agent.sections.core import Collected, describe
from discover_agent.sections.tools import run_tool

SENSITIVE_PACKAGES = frozenset({"login", "libpam-runtime", "util-linux", "sudo", "openssh-server"})
DEBSUMS_TIMEOUT = 280  # the section timeout is 300 s
BATCH = 500
MODULES_DIR = "/lib/modules"
_MISSING = "missing file"
_ERROR_MAX = 500


_MERGED = ("bin", "sbin", "lib", "lib32", "lib64", "libx32")


def canonical(path):
    """`/usr/bin/x` and `/bin/x` name the same file on a merged-/usr system."""
    for name in _MERGED:
        if path.startswith(f"/usr/{name}/"):
            return path[4:]
    return path


def _variants(path):
    """The path as given, plus its other spelling under merged /usr."""
    short = canonical(path)
    if short != path:
        return [path, short]
    if any(path.startswith(f"/{name}/") for name in _MERGED):
        return [path, f"/usr{path}"]
    return [path]


def _owners(runner, paths, errors):
    """Map canonical(path) -> first owning package."""
    wanted = {canonical(p) for p in paths}
    queries = sorted({v for p in paths for v in _variants(p)})
    owners = {}
    for start in range(0, len(queries), BATCH):
        batch = queries[start:start + BATCH]
        try:
            out = run_tool(runner, ["dpkg-query", "-S", "--"] + batch, ok_codes=(0, 1))
        except util.NotInstalled:
            raise
        except Exception as exc:
            errors["dpkg-query"] = describe(exc)
            continue
        for line in out.splitlines():
            names, sep, path = line.partition(": ")
            first = names.split(",")[0].strip()
            # "diversion by x from: /bin/foo" is not a package
            if not sep or not first or " " in first or canonical(path) not in wanted:
                continue
            owners.setdefault(canonical(path), first.split(":")[0])  # no :arch
    return owners


def modules_visible(listdir=os.listdir):
    try:
        return bool(listdir(MODULES_DIR))
    except OSError:
        return False


def _run_debsums(run_full):
    try:
        return run_tool(run_full, ["debsums", "-c"], timeout=DEBSUMS_TIMEOUT, env=util.c_locale_env())
    except util.CommandTimeout as exc:
        raise util.CollectorError("timeout") from exc  # the runner's own sentinel


def _check_status(code, out, err):
    if code == 0 or code == 2:
        return
    failed = code < 0 or code >= 4 or (not out.strip() and _MISSING not in err)
    if failed:
        detail = redact(err.strip())[:_ERROR_MAX]
        raise util.CollectorError(f"debsums a échoué (code {code}) : {detail}" if detail
                                  else f"debsums a échoué (code {code})")


def collect(runner=util.run, run_full=util.run_full, modules_visible=modules_visible):
    code, out, err = _run_debsums(run_full)
    _check_status(code, out, err)
    paths = sorted({line.strip() for line in out.splitlines() if line.strip().startswith("/")})
    errors = {}
    owners = _owners(runner, paths, errors) if paths else {}
    rows = []
    for path in paths:
        package = owners.get(canonical(path))
        rows.append({"path": path, "package": package, "sensitive": package in SENSITIVE_PACKAGES})
    data = {
        "changed": rows,
        "missing": sum(_MISSING in line for line in err.splitlines()),
        "modules_checked": modules_visible(),
    }
    return Collected(data, errors)
