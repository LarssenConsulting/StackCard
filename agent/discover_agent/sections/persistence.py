"""Section `persistence`: crons, unpackaged systemd units, login users, SSH keys, sudoers, GitHub runners.

This reads the most sensitive files of the server, so only derived data leaves:
a fingerprint and never a key, `/etc/passwd` fields and never `/etc/shadow`,
redacted lines and nothing from a runner's `.credentials*` or `.runner`.

`data[name]` is null when nothing of that source could be read
(`source_errors[name]`), or when it has no row and a part of it failed: a refused
file must not read as "nothing there". A part that failed is reported as
`source_errors["name:path"]`, and the rows of the other parts are kept.

`authorized_keys` is read through `read_regular_file`, which follows symlinks, so
such a file can point anywhere on the server. Only well-formed key lines ever
produce rows: whatever else the target holds (`/etc/shadow`, a private key) is
dropped, and of a key line only the type, fingerprint and redacted comment leave.
A certificate has no fingerprint here (`certificate: true`), since `ssh-keygen`
would not print the hash of its blob.
"""

import base64
import binascii
import hashlib
import os
import posixpath
import re
import shlex
from dataclasses import dataclass, field
from typing import Callable

from discover_agent import util
from discover_agent.redact import redact, redact_sudoers
from discover_agent.sections.core import Collected, read_source

PASSWD = "/etc/passwd"
CRONTAB = "/etc/crontab"
CRON_D = "/etc/cron.d"
CRON_SPOOL = "/var/spool/cron/crontabs"
SUDOERS_D = "/etc/sudoers.d"
UNIT_DIRS = ("/etc/systemd/system", "/lib/systemd/system")
DPKG_INFO = "/var/lib/dpkg/info"
KEY_FILES = ("authorized_keys", "authorized_keys2")
UNIT_SUFFIXES = (".service", ".timer", ".socket", ".path", ".mount", ".target")
# Shipped with the runner; follows the `bin` symlink after a self-update. Holds no secret.
RUNNER_VERSION_FILE = "bin/Runner.Listener.deps.json"

_NO_LOGIN_SHELLS = ("nologin", "false", "sync", "halt", "shutdown")
_RUNNER_UNIT = re.compile(r"actions\.runner\..+\.service\Z")
_RUNNER_VERSION = re.compile(r'"Runner\.Listener/(\d+\.\d+\.\d+[0-9A-Za-z.\-]*)"')
_KEY_TYPE = re.compile(
    r"(?:ssh-(?:rsa|dss|ed25519)|ecdsa-sha2-nistp\d+|sk-(?:ssh-ed25519|ecdsa-sha2-nistp\d+))"
    r"(?:-cert-v01)?(?:@openssh\.com)?\Z"
)
# `#include`, `#includedir` and `#<uid>` are sudoers syntax, not comments.
_SUDOERS_NOT_COMMENT = re.compile(r"#(?:include(?:dir)?[ \t]|-?\d)")
_CRON_D_NAME = re.compile(r"[A-Za-z0-9_-]+\Z")  # cron skips any other file name in /etc/cron.d
_CRON_ENV_LINE = re.compile(r"""['"]?[A-Za-z_]\w*['"]?[ \t]*=""")
_USER_NAME = re.compile(r"[A-Za-z_][A-Za-z0-9_.\-]*\$?\Z")
_RAW_KEY_WORD = re.compile(r"AAAA[A-Za-z0-9+/]{16,}={0,2}\Z")
_EXEC_START = re.compile(r"ExecStart[ \t]*=[ \t]*(.*)\Z", re.DOTALL)


@dataclass
class _Host:
    reader: Callable
    list_reader: Callable
    listdir: Callable
    islink: Callable
    exists: Callable
    errors: dict = field(default_factory=dict)

    def names(self, directory):
        """Sorted names in `directory`; absent is empty, any other failure raises."""
        try:
            return sorted(self.listdir(directory))
        except FileNotFoundError:
            return []

    def read(self, path):
        """Text of `path`, None if absent. `read_regular_file` returns None for
        both absent and refused (FIFO, too large): only the second is a failure."""
        text = self.reader(path)
        if text is None and self.exists(path):
            raise util.CollectorError(f"unreadable: {path}")
        return text


def _read_parts(errors, name, parts):
    """Concatenate the rows of (label, fn) parts; null only if every part failed."""
    rows, failed = [], []
    for label, fn in parts:
        found = read_source(errors, f"{name}:{label}", fn)
        if found is None:
            failed.append(label)
        else:
            rows += found
    if len(failed) == len(parts):
        errors[name] = "; ".join(errors.pop(f"{name}:{label}") for label in failed)
        return None
    return rows


def _or_null(errors, name, rows):
    """No row while a part of the source failed is "unknown", not "nothing there"."""
    if not rows and any(key.startswith(f"{name}:") for key in errors):
        return None
    return rows


def _physical_lines(text):
    # Not splitlines(): \x0c or   would cut `KEY=\x0csecret` away from its key.
    return (line.strip() for line in text.split("\n"))


def _logical_lines(text, is_comment):
    """Lines with backslash continuations joined, so a secret is redacted with its key."""
    pending = []
    for line in _physical_lines(text):
        if not line and not pending:
            continue
        if is_comment(line):
            continue
        if line.endswith("\\"):
            pending.append(line[:-1].rstrip())
            continue
        pending.append(line)
        yield " ".join(part for part in pending if part)
        pending = []
    if pending:
        yield " ".join(part for part in pending if part)


# Crons


def _system_cron_user(line):
    """User field of a redacted /etc/crontab or cron.d line, None on an environment line."""
    if _CRON_ENV_LINE.match(line):
        return None
    index = 1 if line.startswith("@") else 5
    words = line.split(None, index + 1)
    user = words[index] if len(words) > index else None
    return user if user and _USER_NAME.match(user) else None


def _cron_rows(path, text, user=None, ignored=False):
    """`user` is the owner of a user crontab; a system crontab names it on each line."""
    rows = []
    for line in _physical_lines(text):
        if line and not line.startswith("#"):
            masked = redact(line)
            rows.append(
                {"file": path, "user": user or _system_cron_user(masked), "ignored": ignored, "line": masked}
            )
    return rows


def _cron_dir(host, directory, user_crontabs):
    rows = []
    for name in host.names(directory):
        path = f"{directory}/{name}"
        text = read_source(host.errors, f"crons:{path}", lambda: host.read(path))
        if text is not None:
            if user_crontabs:
                rows += _cron_rows(path, text, user=name)
            else:
                rows += _cron_rows(path, text, ignored=not _CRON_D_NAME.match(name))
    return rows


def read_crons(host):
    rows = _read_parts(
        host.errors,
        "crons",
        [
            (CRONTAB, lambda: _cron_rows(CRONTAB, host.read(CRONTAB) or "")),
            (CRON_D, lambda: _cron_dir(host, CRON_D, False)),
            (CRON_SPOOL, lambda: _cron_dir(host, CRON_SPOOL, True)),
        ],
    )
    return None if rows is None else _or_null(host.errors, "crons", sorted(rows, key=lambda r: (r["file"], r["line"])))


# Sudoers


def _is_sudoers_comment(line):
    return line.startswith("#") and not _SUDOERS_NOT_COMMENT.match(line)


def _sudo_skips(name):
    return "." in name or name.endswith("~")


def _read_sudoers_files(host):
    rows = []
    for name in host.names(SUDOERS_D):
        path = f"{SUDOERS_D}/{name}"
        text = read_source(host.errors, f"sudoers:{path}", lambda: host.read(path))
        for line in _logical_lines(text or "", _is_sudoers_comment):
            rows.append({"file": path, "ignored": _sudo_skips(name), "line": redact_sudoers(line)})
    return _or_null(host.errors, "sudoers", sorted(rows, key=lambda r: (r["file"], r["line"])))


# Users and keys


def _passwd_entries(reader):
    text = reader(PASSWD)
    if text is None:
        raise util.CollectorError(f"unreadable: {PASSWD}")
    entries = []
    for line in text.split("\n"):
        # The password field is never kept: old systems may still hold a hash there.
        fields = line.split(":")
        if len(fields) != 7 or not fields[0] or not fields[2].isdecimal():
            continue
        entries.append({"name": fields[0], "uid": int(fields[2]), "home": fields[5], "shell": fields[6].strip()})
    return entries


def _login_users(entries):
    users = [
        {"name": e["name"], "uid": e["uid"], "shell": e["shell"], "home": e["home"]}
        for e in entries
        if e["shell"].rsplit("/", 1)[-1] not in _NO_LOGIN_SHELLS
    ]
    return sorted(users, key=lambda u: u["name"])


def _after_options(line):
    """What follows the options field, read as sshd does: only `\\"` is an escape."""
    quoted, i, n = False, 0, len(line)
    while i < n:
        if line.startswith('\\"', i):
            i += 2
            continue
        c = line[i]
        if c == '"':
            quoted = not quoted
        elif c in " \t" and not quoted:
            return line[i:]
        i += 1
    return ""


def _decode_key(key_type, encoded):
    """The key blob, None if it is not a key of that type."""
    try:
        blob = base64.b64decode(encoded, validate=True)
    except (binascii.Error, ValueError):
        return None
    name = key_type.encode()
    if blob[:4] != len(name).to_bytes(4, "big") or blob[4 : 4 + len(name)] != name:
        return None
    return blob


def _fingerprint(blob):
    digest = base64.b64encode(hashlib.sha256(blob).digest()).decode()
    return "SHA256:" + digest.rstrip("=")


def _safe_comment(comment):
    """Redacted comment; two keys pasted on one line would put the second one here."""
    words = [w for w in comment.split() if not (_KEY_TYPE.match(w) or _RAW_KEY_WORD.match(w))]
    return redact(" ".join(words)) if words else None


def parse_key_line(line):
    """Row fields of a key line, None if malformed. Options are dropped."""
    words = line.split(None, 1)
    if not words:
        return None
    rest = line if _KEY_TYPE.match(words[0]) else _after_options(line)
    words = rest.split(None, 2)
    if len(words) < 2 or not _KEY_TYPE.match(words[0]):
        return None
    blob = _decode_key(words[0], words[1])
    if blob is None:
        return None
    certificate = "-cert-" in words[0]
    return {
        "type": words[0],
        "fingerprint": None if certificate else _fingerprint(blob),
        "certificate": certificate,
        "comment": _safe_comment(words[2]) if len(words) > 2 else None,
    }


def _read_keys(host, entries):
    # Every account, not only login shells: sshd still forwards ports for a nologin one.
    rows = []
    for entry in entries:
        if not entry["home"].startswith("/"):
            continue
        for key_file in KEY_FILES:
            path = f"{entry['home'].rstrip('/')}/.ssh/{key_file}"
            text = read_source(host.errors, f"authorized_keys:{path}", lambda: host.read(path))
            for line in _physical_lines(text or ""):
                parsed = None if line.startswith("#") else parse_key_line(line)
                if parsed:
                    rows.append({"user": entry["name"], "file": path, **parsed})
    rows.sort(key=lambda r: (r["user"], r["file"], r["fingerprint"] or "", r["comment"] or ""))
    return _or_null(host.errors, "authorized_keys", rows)


# Units


def _same_path(path):
    """/lib is /usr/lib on Ubuntu, and packages list either spelling."""
    return path[len("/usr") :] if path.startswith("/usr/lib/") else path


def _is_unit_file(path):
    return path.endswith(UNIT_SUFFIXES) or (path.endswith(".conf") and "/systemd/system/" in path)


def packaged_units(host):
    """Unit paths known to dpkg, from all the .list files read once (no `dpkg -S` per file).

    A list that cannot be read would turn its units into false orphans, so it fails the source.
    """
    names = [n for n in host.names(DPKG_INFO) if n.endswith(".list")]
    if not names:
        raise util.CollectorError(f"no package list in {DPKG_INFO}")
    known = set()
    for name in names:
        text = host.list_reader(f"{DPKG_INFO}/{name}")
        if text is None:
            raise util.CollectorError(f"unreadable: {DPKG_INFO}/{name}")
        known.update(_same_path(p) for p in text.split("\n") if _is_unit_file(p))
    return known


def parse_exec_start(text):
    """The ExecStart commands of a unit, redacted. An empty assignment resets the list, as in systemd."""
    commands = []
    for line in _logical_lines(text, lambda line: line.startswith(("#", ";"))):
        m = _EXEC_START.match(line)
        if not m:
            continue
        if m.group(1):
            commands.append(redact(m.group(1)))
        else:
            commands = []
    return commands


def _unit_paths(host, directory):
    """Top-level unit files of `directory`; symlinks and `*.wants` directories are not units."""
    paths = []
    for name in host.names(directory):
        path = f"{directory}/{name}"
        if name.endswith(UNIT_SUFFIXES) and not host.islink(path):
            paths.append(path)
    return paths


def _drop_in_names(host, directory):
    try:
        return host.names(directory)
    except NotADirectoryError:  # a plain file that happens to end in `.d`
        return []


def _drop_in_paths(host, directory):
    """`*.d/*.conf` of `directory`: overriding a packaged unit needs no new unit file."""
    paths = []
    for sub in host.names(directory):
        sub_path = f"{directory}/{sub}"
        if not sub.endswith(".d") or host.islink(sub_path):
            continue
        names = read_source(host.errors, f"units:{sub_path}", lambda: _drop_in_names(host, sub_path))
        for name in names or []:
            path = f"{sub_path}/{name}"
            if name.endswith(".conf") and not host.islink(path):
                paths.append(path)
    return paths


def _exec_start(host, source, path):
    text = read_source(host.errors, f"{source}:{path}", lambda: host.read(path))
    return None if text is None else parse_exec_start(text)


def _orphans(host, directory, known):
    rows = []
    prefix = len(directory) + 1
    for path, drop_in in [(p, False) for p in _unit_paths(host, directory)] + [
        (p, True) for p in _drop_in_paths(host, directory)
    ]:
        if _same_path(path) not in known:
            rows.append(
                {"name": path[prefix:], "path": path, "exec_start": _exec_start(host, "units", path), "drop_in": drop_in}
            )
    return rows


def read_units(host):
    known = read_source(host.errors, "units", lambda: packaged_units(host))
    if known is None:
        return None
    parts = [(d, lambda d=d: _orphans(host, d, known)) for d in UNIT_DIRS]
    rows = _read_parts(host.errors, "units", parts)
    return None if rows is None else _or_null(host.errors, "units", sorted(rows, key=lambda r: r["path"]))


# GitHub runners


def runner_directory(command):
    """Directory of the program of an ExecStart command, None if it is not an absolute path."""
    # systemd prefixes such as `-` or `+` can sit in front of a quoted path.
    command = command.lstrip("-@:+!|")
    try:
        words = shlex.split(command)
    except ValueError:
        words = command.split()
    if not words or not words[0].startswith("/"):
        return None
    return posixpath.dirname(posixpath.normpath(words[0]))


def _runner_version(reader, directory):
    # Only this file is ever opened here: never `.credentials*` or `.runner`.
    text = reader(f"{directory}/{RUNNER_VERSION_FILE}")
    m = _RUNNER_VERSION.search(text) if text else None
    return m.group(1) if m else None


def _runners(host, directory):
    rows = []
    for path in _unit_paths(host, directory):
        name = posixpath.basename(path)
        if not _RUNNER_UNIT.match(name):
            continue
        commands = _exec_start(host, "github_runners", path)
        runner_dir = runner_directory(commands[0]) if commands else None
        version = _runner_version(host.reader, runner_dir) if runner_dir else None
        rows.append({"service": name, "directory": runner_dir, "version": version})
    return rows


def read_runners(host):
    parts = [(d, lambda d=d: _runners(host, d)) for d in UNIT_DIRS]
    rows = _read_parts(host.errors, "github_runners", parts)
    if rows is None:
        return None
    return _or_null(host.errors, "github_runners", sorted(rows, key=lambda r: (r["service"], r["directory"] or "")))


def collect(
    reader=util.read_regular_file,
    list_reader=util.read_text,  # dpkg's own files: a .list can exceed the 1 MiB of user files
    listdir=os.listdir,
    islink=os.path.islink,
    exists=os.path.lexists,
):
    host = _Host(reader, list_reader, listdir, islink, exists)
    errors = host.errors
    entries = read_source(errors, "users", lambda: _passwd_entries(reader))
    if entries is None:
        errors["authorized_keys"] = f"unreadable: {PASSWD}"
    data = {
        "crons": read_crons(host),
        "units": read_units(host),
        "users": None if entries is None else _login_users(entries),
        "authorized_keys": None if entries is None else _read_keys(host, entries),
        "sudoers": read_source(errors, "sudoers", lambda: _read_sudoers_files(host)),
        "github_runners": read_runners(host),
    }
    return Collected(data, errors)
