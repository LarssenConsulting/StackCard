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


def read_regular_file(path, limit=1024 * 1024):
    """Read a file a non-root user may control: regular files under `limit` only.

    O_NONBLOCK keeps a FIFO from hanging the agent; the fstat checks run on the
    opened descriptor so a swapped symlink cannot slip past them.
    """
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NONBLOCK | getattr(os, "O_NOCTTY", 0))
    except OSError:
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
