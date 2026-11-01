"""Small helpers shared by every collector."""

import os
import shutil
import stat
import subprocess
from datetime import datetime, timezone

DEFAULT_TIMEOUT = 15

# Substrings (matched case-insensitively) that mark a key or option name as
# holding a secret. Shared by dbrefs (config parsing) and redact (free text).
SENSITIVE_MARKERS = ("PASS", "SECRET", "KEY", "TOKEN", "SALT")


class CommandError(Exception):
    """A system command could not be run, timed out or exited non-zero."""


class CommandTimeout(CommandError):
    """A system command was stopped because it ran past its timeout."""


class NotInstalled(Exception):
    """The software a section describes is absent: there is nothing to collect."""


class CollectorError(Exception):
    """A collector failed with a message meant for the administrator."""


def _execute(cmd, timeout, input, cwd, env):
    if cwd is not None and not os.path.isdir(cwd):
        raise CommandError(f"cwd is not a directory: {cwd}")
    try:
        # Command output is not ours to trust: a byte that is not UTF-8 must not cost the collector.
        return subprocess.run(
            cmd,
            capture_output=True,
            encoding="utf-8",
            errors="replace",
            timeout=timeout,
            input=input,
            cwd=cwd,
            env=env,
        )
    except FileNotFoundError as exc:
        raise CommandError(f"command not found: {cmd[0]}") from exc
    except NotADirectoryError as exc:
        raise CommandError(f"cwd is not a directory: {cwd}") from exc
    except subprocess.TimeoutExpired as exc:
        raise CommandTimeout(f"timeout after {timeout}s: {' '.join(cmd)}") from exc


def run_full(cmd, timeout=DEFAULT_TIMEOUT, env=None, cwd=None):
    """Run `cmd` and return (returncode, stdout, stderr), for tools whose stderr or status is the answer."""
    proc = _execute(cmd, timeout, None, cwd, env)
    return proc.returncode, proc.stdout, proc.stderr


def run(cmd, timeout=DEFAULT_TIMEOUT, input=None, cwd=None, env=None, ok_codes=(0,)):
    proc = _execute(cmd, timeout, input, cwd, env)
    if proc.returncode not in ok_codes:
        raise CommandError(
            f"{' '.join(cmd)} exited {proc.returncode}: {proc.stderr.strip()[:500]}"
        )
    return proc.stdout


def c_locale_env(env=None):
    """A copy of `env` (default: this process's) in the C locale."""
    env = dict(os.environ if env is None else env)
    env["LC_ALL"] = "C"
    env["LANGUAGE"] = "C"
    return env


def run_c(cmd, **kwargs):
    """`run` in the C locale, for output that is parsed by its English wording."""
    return run(cmd, env=c_locale_env(kwargs.pop("env", None)), **kwargs)


def read_text(path):
    try:
        with open(path, encoding="utf-8", errors="replace") as fh:
            return fh.read()
    except OSError:
        return None


_READ_FLAGS = os.O_RDONLY | os.O_NONBLOCK | getattr(os, "O_NOCTTY", 0)


def _open_checked(path, limit):
    """Return a read descriptor on `path` only if it is a regular file under `limit`."""
    o_path = getattr(os, "O_PATH", None)
    if o_path is not None:
        # Linux: an O_PATH descriptor never runs a device's open(). The type is
        # checked on that descriptor and the very same inode is then reopened
        # through /proc, so a symlink swapped in between changes nothing.
        handle = os.open(path, o_path)
        try:
            st = os.fstat(handle)
            if not stat.S_ISREG(st.st_mode) or st.st_size > limit:
                return None
            return os.open(f"/proc/self/fd/{handle}", _READ_FLAGS)
        finally:
            os.close(handle)
    # Elsewhere (developer machines only): best effort, checked again after opening.
    if not stat.S_ISREG(os.stat(path).st_mode):
        return None
    return os.open(path, _READ_FLAGS)


def read_regular_file(path, limit=1024 * 1024):
    """Read a file a non-root user may control: regular files under `limit` only.

    Root never opens a device or FIFO a site owner points a symlink at (opening
    some devices has side effects; O_NONBLOCK keeps a FIFO from hanging the
    agent). Symlinks to regular files are followed on purpose: deploy tools
    link .env to a shared copy.
    """
    try:
        fd = _open_checked(path, limit)
    except OSError:
        return None
    if fd is None:
        return None
    try:
        st = os.fstat(fd)
        if not stat.S_ISREG(st.st_mode) or st.st_size > limit:
            return None
        data = os.read(fd, limit + 1)
    except OSError:
        return None
    finally:
        os.close(fd)
    if len(data) > limit:
        return None
    return data.decode("utf-8", errors="replace")


def iso_utc(dt):
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")


def now_utc():
    return iso_utc(datetime.now(timezone.utc))


def which(name):
    return shutil.which(name) is not None
