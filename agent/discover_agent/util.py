"""Small helpers shared by every collector."""

import os
import shutil
import stat
import subprocess
from datetime import datetime, timezone

DEFAULT_TIMEOUT = 15


class CommandError(Exception):
    """A system command could not be run, timed out or exited non-zero."""


class CollectorError(Exception):
    """A collector failed with a message meant for the administrator."""


def run(cmd, timeout=DEFAULT_TIMEOUT, input=None):
    try:
        proc = subprocess.run(
            cmd, capture_output=True, text=True, timeout=timeout, input=input
        )
    except FileNotFoundError as exc:
        raise CommandError(f"command not found: {cmd[0]}") from exc
    except subprocess.TimeoutExpired as exc:
        raise CommandError(f"timeout after {timeout}s: {' '.join(cmd)}") from exc
    if proc.returncode != 0:
        raise CommandError(
            f"{' '.join(cmd)} exited {proc.returncode}: {proc.stderr.strip()[:500]}"
        )
    return proc.stdout


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
