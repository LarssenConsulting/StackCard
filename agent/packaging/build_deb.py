#!/usr/bin/env python3
"""Build discover-agent_<version>_all.deb with the standard library only.

A .deb is an `ar` archive of debian-binary, control.tar.gz and data.tar.gz, so
no dpkg-deb (and no Docker) is needed: this runs on macOS as well as Linux.

Usage: python3 agent/packaging/build_deb.py [output_dir]   (default: agent/dist)
"""

import io
import os
import sys
import tarfile
import time

PACKAGING = os.path.dirname(os.path.abspath(__file__))
AGENT = os.path.dirname(PACKAGING)
sys.path.insert(0, AGENT)

from discover_agent import __version__  # noqa: E402

CONTROL = """Package: discover-agent
Version: {version}
Architecture: all
Maintainer: Larssen Consulting <noreply@larssen-consulting.com>
Depends: python3 (>= 3.10), iproute2, openssl
Section: admin
Priority: optional
Description: Discover inventory agent
 Collects server identity, Apache sites, MySQL databases and the users
 linked to them, and sends the inventory over HTTPS to the Discover central.
"""

MAINTAINER_SCRIPTS = ("postinst", "prerm", "postrm")


def _add(tar, name, data, mode):
    info = tarfile.TarInfo(name)
    info.size, info.mode, info.mtime = len(data), mode, int(time.time())
    info.uid = info.gid = 0
    info.uname = info.gname = "root"
    tar.addfile(info, io.BytesIO(data))


def _add_dir(tar, name):
    info = tarfile.TarInfo(name)
    info.type, info.mode, info.mtime = tarfile.DIRTYPE, 0o755, int(time.time())
    info.uid = info.gid = 0
    info.uname = info.gname = "root"
    tar.addfile(info)


def _read(path):
    with open(path, "rb") as fh:
        return fh.read()


def _tar_gz(entries):
    """entries: list of (name, bytes | None for a directory, mode)."""
    buffer = io.BytesIO()
    with tarfile.open(fileobj=buffer, mode="w:gz", format=tarfile.GNU_FORMAT) as tar:
        for name, data, mode in entries:
            if data is None:
                _add_dir(tar, name)
            else:
                _add(tar, name, data, mode)
    return buffer.getvalue()


def _package_files():
    files = []
    source = os.path.join(AGENT, "discover_agent")
    for root, dirs, names in os.walk(source):
        dirs[:] = sorted(d for d in dirs if d != "__pycache__")
        for name in sorted(names):
            if name.endswith(".py"):
                rel = os.path.relpath(os.path.join(root, name), AGENT)
                files.append((f"./usr/lib/discover-agent/{rel}", _read(os.path.join(root, name)), 0o644))
    return files


def _data_entries():
    files = _package_files() + [
        ("./usr/bin/discover-agent", _read(os.path.join(PACKAGING, "bin", "discover-agent")), 0o755),
        ("./lib/systemd/system/discover-agent.service",
         _read(os.path.join(PACKAGING, "systemd", "discover-agent.service")), 0o644),
        ("./lib/systemd/system/discover-agent.timer",
         _read(os.path.join(PACKAGING, "systemd", "discover-agent.timer")), 0o644),
    ]
    dirs = set()
    for name, _, _ in files:
        parent = os.path.dirname(name)
        while parent not in (".", ""):
            dirs.add(parent)
            parent = os.path.dirname(parent)
    return [(d, None, 0o755) for d in sorted(dirs)] + files


def _ar(members):
    out = io.BytesIO()
    out.write(b"!<arch>\n")
    now = int(time.time())
    for name, data in members:
        header = (
            f"{name:<16}{now:<12}{0:<6}{0:<6}{0o100644:<8o}{len(data):<10}`\n"
        ).encode()
        out.write(header)
        out.write(data)
        if len(data) % 2:
            out.write(b"\n")
    return out.getvalue()


def build(out_dir=None):
    out_dir = out_dir or os.path.join(AGENT, "dist")
    os.makedirs(out_dir, exist_ok=True)
    control = [("./control", CONTROL.format(version=__version__).encode(), 0o644)] + [
        (f"./{name}", _read(os.path.join(PACKAGING, "debian", name)), 0o755)
        for name in MAINTAINER_SCRIPTS
    ]
    deb = _ar([
        ("debian-binary", b"2.0\n"),
        ("control.tar.gz", _tar_gz(control)),
        ("data.tar.gz", _tar_gz(_data_entries())),
    ])
    path = os.path.join(out_dir, f"discover-agent_{__version__}_all.deb")
    with open(path, "wb") as fh:
        fh.write(deb)
    return path


if __name__ == "__main__":
    print(build(sys.argv[1] if len(sys.argv) > 1 else None))
