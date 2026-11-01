"""Mask secrets in free text before it leaves the server.

Every free-text value the agent sends (cron lines, service commands, package
sources...) goes through `redact`. A false negative leaks a secret, so the
rules lean towards masking; the only protected shapes are the ones listed in
the tests (for example `MAILTO=admin@example.com`).

The input is hostile (anyone who can write a cron line or a unit file controls
it), so each rule is a linear pass: no nested quantifiers, and the key/value and
command rules work line by line (a quote never spans a newline) with per-line
state computed once.
"""

import re

from discover_agent.util import SENSITIVE_MARKERS

MASK = "***"

# Names that only mark a secret as a whole word of the name (AUTHOR is not one).
_WORD_MARKERS = ("AUTH", "PWD")

_RUNS = re.compile(r"\S+")
_USER = re.compile(r"[^:/@]*")
_SCHEME_TAIL = re.compile(r"[A-Za-z0-9+.\-]*\Z")
# Over these a lone `user@` is a token more often than a login. ssh and git keep theirs.
_WEB_SCHEMES = frozenset({"http", "https", "ftp", "ftps"})
_AUTH_HEADER = re.compile(
    r"(Authorization:[ \t]*(?:(?:Bearer|Basic|Token)[ \t]+)?)[^\s'\"]+", re.IGNORECASE
)
# Variable or option names; the lookbehind keeps matches on word starts.
_NAME = re.compile(r"(?<![\w\-])(-{0,2})[A-Za-z_][\w\-]*")
_FIRST_WORD = re.compile(r"[ \t]*(\S+)")
_URL_VALUE = re.compile(r"[^&#\s]*")
_JSON_BARE = re.compile(r"[^,}\]\s]*")
_PASSWORD_COMMAND = re.compile(
    r"(?<![\w.\-])(mysql[a-z]*|mariadb(?:-[a-z]+)?|sshpass)(?=[ \t])"
)
# Tools whose credential option has no marker in its name. docker's `-p` is `--publish` outside `login`.
_CREDENTIAL_TOOL = re.compile(r"(?<![\w.\-])(curl|redis-cli|docker)(?=[ \t])")
# curl groups short options: `-su`, `-fsSLu`. Only flags without a value may come before the `u`.
_CURL_USER = re.compile(r"-[aBfgGiIjJklLnNOpqRsSvV0-9]*u")
_NEVER_SECRET = frozenset({"NOPASSWD"})  # sudoers tag, not a credential
_QUOTES = "'\""
_CONTINUED_SPLIT = re.compile(r"(?<!\\)\n")
_FLAG = re.compile(r"(?:--\S*|-[A-Za-z]{1,2})(?=[ \t]|\Z)")
_GLUED_WORD = re.compile(r"\S{1,64}")
_LOWER_IDENT = re.compile(r"[a-z][a-z_:\-]*\Z")
_CREDENTIAL_WORDS = frozenset({"password", "passwd", "pwd", "secret", "token"})
# Secrets recognised by their shape, wherever they sit: `usermod -p '$6$...'`, a pasted SSH key.
_CRYPT_SEGMENT = r"(?:\d{1,2}|[A-Z_][A-Z0-9_]*|[a-z]{1,2})"
_CRYPT_BODY = r"[./A-Za-z0-9$\\=,+\-]"
# A positional-parameter lookalike (`$1$2`, `$1$HOME`) is not a hash. The repeat is
# bounded so the lookahead stays linear: unbounded, `$1$1$1...` rescans from every `$1$`.
_CRYPT_HASH = re.compile(
    r"\$(?:(?:2[abxy]|gy|argon2\w*)\\?\$|[156y]\\?\$(?!"
    + _CRYPT_SEGMENT + r"(?:\\?\$" + _CRYPT_SEGMENT + r"){0,8}(?!" + _CRYPT_BODY + r")))"
    + _CRYPT_BODY + r"+"
)
_KEY_BLOB = re.compile(r"(?<![A-Za-z0-9+/])AAAA[A-Za-z0-9+/]{16,}={0,2}")

# Sudoers syntax that the key/value rules would take for a secret. `PASSWD:` is only
# the tag where a tag can sit, so a line-initial `PASSWD: x` is still masked.
_SUDOERS_TAG = re.compile(r"(?<=[ \t=),])PASSWD:(?=[ \t\n]|\Z)")
_SUDOERS_ALIAS = re.compile(r"[ \t]*(?:User|Runas|Host|Cmnd|Cmd)_Alias[ \t]+[A-Z][A-Z0-9_]*[ \t]*=")
_SUDOERS_NEXT_ALIAS = re.compile(r":[ \t]*[A-Z][A-Z0-9_]*[ \t]*=")
_SLOT = re.compile("\x00(\\d+)\x00")

# What may follow `NAME=secret` on a line-initial assignment without being
# part of the value (`PGPASSWORD=x pg_dump db`). Anything else is taken as
# the rest of the value, which is what cron does with `NAME=a b c`.
_KNOWN_COMMANDS = frozenset(
    "pg_dump pg_dumpall pg_restore psql createdb dropdb redis-cli mongodump "
    "mongorestore mongo mongosh curl wget php python python3 node npm npx "
    "bash sh env docker rsync ssh scp sshpass java ruby perl nice ionice "
    "flock timeout sudo su run-parts cd test composer artisan git tar gzip".split()
)


def _is_secret_name(name):
    upper = name.upper()
    if upper in _NEVER_SECRET:
        return False
    if any(marker in upper for marker in SENSITIVE_MARKERS):
        return True
    parts = re.split(r"[_\-]", upper)
    return any(marker in parts for marker in _WORD_MARKERS)


def _glued_secret(name, text, v, quote):
    """Is `name:value` (colon glued to the value) a secret?

    Compound names (`X-Api-Key`, `DB_PASSWORD`) and anything inside quotes: yes.
    A bare word only when it is exactly a credential word and the value is not a
    lowercase identifier, so `token:S3cret` is masked but `artisan token:prune`,
    `key:generate` and `passport:purge` are not. Paths (`keys:/etc/keys`) never are.
    """
    if text[v] == "/":
        return False
    bare = name.lstrip("-")
    if quote is not None or "-" in bare or "_" in bare:
        return True
    if bare.lower() not in _CREDENTIAL_WORDS:
        return False
    word = _GLUED_WORD.match(text, v).group()
    return len(word) == 64 or not _LOWER_IDENT.match(word)


def _skip_ws(text, i):
    """Skip blanks and backslash-newline continuations."""
    n = len(text)
    while i < n:
        if text[i] in " \t":
            i += 1
        elif text[i] == "\\" and i + 1 < n and text[i + 1] == "\n":
            i += 2
        else:
            break
    return i


def _command_like(word):
    if word.startswith(("/", "./", "../", "~")):
        return True
    base = word.rsplit("/", 1)[-1]
    return base in _KNOWN_COMMANDS or base.startswith(("mysql", "mariadb"))


class _Scanner:
    """Quote-aware shell word scanning over one line."""

    def __init__(self, text):
        self.text = text
        self.n = len(text)
        # Once a quote is found unterminated, later ones cannot be closed
        # either: remember it instead of searching to the end again.
        self._open_failed = {"'": False, '"': False}

    def quoted(self, i):
        """End of the quoted string starting at `i`; unterminated: end of line."""
        text, quote = self.text, self.text[i]
        if not self._open_failed[quote]:
            k = i + 1
            while k < self.n:
                c = text[k]
                if c == quote:
                    return k + 1
                k += 2 if (quote == '"' and c == "\\") else 1
            self._open_failed[quote] = True
        return self.n

    def until_close(self, i, quote):
        """Position of the quote closing the string we are inside (or end of line)."""
        text, k = self.text, i
        while k < self.n and text[k] != quote:
            k += 2 if (quote == '"' and text[k] == "\\") else 1
        return min(k, self.n)

    def word(self, i, stops=""):
        """End of the shell word at `i`: quotes and backslashes are part of it."""
        text, n = self.text, self.n
        while i < n:
            c = text[i]
            if c in " \t\r" or c in stops:
                break
            if c == "\\":
                i += 2
            elif c in _QUOTES:
                i = self.quoted(i)
            else:
                i += 1
        return min(i, n)


def _apply(text, edits):
    out, last = [], 0
    for start, end in edits:
        out.append(text[last:start])
        out.append(MASK)
        last = end
    out.append(text[last:])
    return "".join(out)


_PORT_THEN_PATH = re.compile(r":\d{1,5}/")


def _is_web_scheme(word, k):
    """Does the `://` at `k` close http(s) or ftp(s), possibly `git+https`? The look-back is bounded."""
    scheme = _SCHEME_TAIL.search(word, max(0, k - 32), k).group()
    return scheme.rpartition("+")[2].lower() in _WEB_SCHEMES


def _is_url_user(user):
    return bool(user) and "?" not in user and "#" not in user


def _is_port_then_path(word, colon, last_at):
    """`host:8080/pkg@1.2`: the `@` lies in the path, so what follows the colon is a port, not a password."""
    match = _PORT_THEN_PATH.match(word, colon)
    return bool(match) and match.end() <= last_at


def _mask_url_passwords(text):
    edits = []
    for run in _RUNS.finditer(text):
        word, base = run.group(), run.start()
        last_at = word.rfind("@")
        k = word.find("://")
        while 0 <= k < last_at:
            if k > 0 and (word[k - 1].isalnum() or word[k - 1] in "+.-"):
                start = k + 3
                stop = _USER.match(word, start).end()
                # On the web a user part with no password is the secret (`https://ghp_x@host`).
                user_only = (
                    stop <= last_at
                    and (word[stop] == "@" or (word[stop] == ":" and stop + 1 == last_at))
                    and _is_web_scheme(word, k)
                    and _is_url_user(word[start:stop])
                )
                if user_only:
                    if word[start:stop] != MASK:
                        edits.append((base + start, base + stop))
                    after = _USER.match(word, stop + 1).end()  # https://me@corp:S3cret@host
                    if word[stop] == "@" and after < last_at and word[after] == ":":
                        edits.append((base + after + 1, base + last_at))
                        break
                elif stop < last_at and word[stop] == ":" and not _is_port_then_path(word, stop, last_at):
                    edits.append((base + stop + 1, base + last_at))
                    break
            k = word.find("://", k + 3)
    return _apply(text, edits)


def _first_name_pos(line):
    """Index of the first word of the line, after an optional `export`."""
    n = len(line)
    i = 0
    while i < n and line[i] in " \t":
        i += 1
    if line.startswith("export", i) and i + 6 < n and line[i + 6] in " \t":
        i += 6
        while i < n and line[i] in " \t":
            i += 1
    return i


def _mask_assignments(text):
    sc = _Scanner(text)
    n = sc.n
    edits = []
    resume = 0
    first = _first_name_pos(text)
    state, qpos = None, 0  # quote we are inside at qpos
    for m in _NAME.finditer(text):
        start = m.start()
        if start < resume:
            continue
        name = m.group()
        if not _is_secret_name(name):
            continue
        end = m.end()
        pre = text[start - 1] if start else ""
        # Closing quote of a quoted key, possibly escaped: "password": / \"password\":
        if end < n and text[end] in _QUOTES and pre == text[end]:
            json_key, i = True, end + 1
        elif (
            end + 1 < n and text[end] == "\\" and text[end + 1] in _QUOTES and pre == text[end + 1]
        ):
            json_key, i = True, end + 2
        else:
            json_key, i = False, end
            if end < n and text[end] == "[":
                # auth[]=user&auth[]=S3cret, auth[pass]=S3cret: the subscript is part of the name
                close = text.find("]", end + 1, end + 66)
                if close > 0 and not re.search(r"[\s=]", text[end + 1 : close]):
                    i = close + 1
        k = qpos
        while k < start:
            c = text[k]
            if state is None:
                if c == "\\":
                    k += 2
                    continue
                if c in _QUOTES:
                    state = c
            elif c == state:
                state = None
            elif c == "\\" and state == '"':
                k += 2
                continue
            k += 1
        qpos = k
        j = _skip_ws(text, i)
        sep = text[j] if j < n else ""
        if sep == ":" and not json_key:
            nxt = text[j + 1] if j + 1 < n else ""
            if not nxt or pre == "/":
                continue
            if nxt not in " \t" and nxt not in _QUOTES and not _glued_secret(name, text, j + 1, state):
                continue
        if sep in ("=", ":"):
            k = _skip_ws(text, j + 1)
            vstart, spaced = k, (j > i or k > j + 1)
        elif (
            m.group(1)  # --token S3cret, -token S3cret
            and j > i
            and j < n
            and not json_key
            and not _FLAG.match(text, j)
            and not name.lstrip("-").startswith(("no-", "skip-"))
        ):
            vstart, spaced, sep = j, False, " "
        else:
            continue
        if vstart >= n or text[vstart] in "\r":
            continue
        if json_key:
            if text[vstart] == "\\" and vstart + 1 < n and text[vstart + 1] in _QUOTES:
                close = text.find(text[vstart : vstart + 2], vstart + 2)
                vend = n if close < 0 else close + 2
            elif text[vstart] in _QUOTES:
                vend = sc.quoted(vstart)
            else:
                vend = _JSON_BARE.match(text, vstart).end()
        elif pre in ("?", "&"):
            vend = _URL_VALUE.match(text, vstart).end()
        elif state is not None:
            vend = sc.until_close(vstart, state)  # mask through the enclosing quote
        else:
            vend = sc.word(vstart)
            if text[vstart:vend] != MASK and not m.group(1) and start == first:
                rest = _FIRST_WORD.match(text, vend)
                plain = "'" not in text[vstart:vend] and '"' not in text[vstart:vend]
                if rest and (spaced or sep == ":" or (plain and not _command_like(rest.group(1)))):
                    vend = n
        if vend > vstart and text[vstart:vend] != MASK:
            edits.append((vstart, vend))
        resume = max(vend, resume)
    return _apply(text, edits)


def _option_edit(base, word):
    """Span to mask in a glued `-pSECRET` word, which may itself be quoted."""
    q = 1 if word[:1] in ("'", '"') else 0
    if word[q : q + 2] != "-p" or len(word) <= q + 2:
        return None
    start, end = base + q + 2, base + len(word)
    if q and word[-1] == word[0] and len(word) > q + 2:
        end -= 1
    return (start, end) if end > start else None


def _mask_command_passwords(text):
    sc = _Scanner(text)
    edits = []
    pos = 0
    while True:
        m = _PASSWORD_COMMAND.search(text, pos)
        if not m:
            break
        i = m.end()
        if m.group(1) == "sshpass":
            # sshpass's own options come first; the wrapped command (`ssh -p 22`)
            # starts at the first word that is not one of them.
            while True:
                i = _skip_ws(text, i)
                if i >= sc.n or text[i] in ";|&\n\r":
                    break
                end = sc.word(i, ";|&")
                word = text[i:end]
                bare = word.strip("'\"")
                if not bare.startswith("-") or bare == "--":
                    break
                edit = _option_edit(i, word)
                if bare in ("-p", "-f", "-d", "-P"):
                    k = _skip_ws(text, end)
                    vend = sc.word(k, ";|&")
                    if bare == "-p" and vend > k:
                        edits.append((k, vend))
                    if vend > k:
                        end = vend
                elif edit and bare.startswith("-p"):
                    edits.append(edit)
                i = end
            pos = max(i, m.end())
            continue
        while True:
            i = _skip_ws(text, i)
            if i >= sc.n or text[i] in ";|&\n\r":
                break
            end = sc.word(i, ";|&")
            edit = _option_edit(i, text[i:end])
            if edit:
                edits.append(edit)
            i = end
        pos = max(i, m.end())
    edits = [(s, e) for s, e in edits if text[s:e] != MASK]
    return _apply(text, edits)


def _credential_edit(text, start, end):
    """Span to mask in curl's `user:password`. With no password the name itself may be the token."""
    if start < end and text[start] in _QUOTES:
        if end - 1 > start and text[end - 1] == text[start]:
            end -= 1
        start += 1
    colon = text.find(":", start, end)
    if colon < 0:
        return start, end
    return (start, colon) if colon + 1 == end else (colon + 1, end)


def _mask_tool_credentials(text):
    sc = _Scanner(text)
    edits = []
    pos = 0
    while True:
        m = _CREDENTIAL_TOOL.search(text, pos)
        if not m:
            break
        tool, logged_in = m.group(1), False
        i = m.end()
        while True:
            i = _skip_ws(text, i)
            if i >= sc.n or text[i] in ";|&\n\r":
                break
            end = sc.word(i, ";|&")
            word = text[i:end]
            q = 1 if word[:1] in _QUOTES else 0
            bare = word.strip(_QUOTES)
            separate = False  # is the secret the next word?
            if tool == "curl":
                flag = _CURL_USER.match(bare) if not bare.startswith("--") else None
                if bare == "--user" or (flag and flag.end() == len(bare)):
                    separate = True
                elif bare.startswith("--user="):
                    edits.append(_credential_edit(text, i + q + 7, end))
                elif flag:
                    edits.append(_credential_edit(text, i + q + flag.end(), end))
            elif tool == "redis-cli":
                separate = bare == "-a"
            elif word == "login":
                logged_in = True
            elif logged_in:
                if bare == "-p":
                    separate = True
                elif _option_edit(i, word):
                    edits.append(_option_edit(i, word))
            if separate:
                k = _skip_ws(text, end)
                vend = sc.word(k, ";|&")
                if vend > k:
                    edits.append(_credential_edit(text, k, vend) if tool == "curl" else (k, vend))
                    end = vend
            i = end
        pos = max(i, m.end())
    edits = sorted((s, e) for s, e in edits if s < e and text[s:e] != MASK)
    return _apply(text, edits)


def redact(text):
    if text is None:
        return None
    text = _KEY_BLOB.sub(MASK, _CRYPT_HASH.sub(MASK, text))
    text = _mask_url_passwords(text)
    text = _AUTH_HEADER.sub(lambda m: m.group(1) + MASK, text)
    # Logical lines: a backslash-newline continues the line.
    return "\n".join(
        _mask_tool_credentials(_mask_command_passwords(_mask_assignments(line)))
        for line in _CONTINUED_SPLIT.split(text)
    )


def redact_sudoers(text):
    """`redact` for a sudoers line: the `PASSWD:` tag and alias names are syntax, not secrets.

    They are hidden behind slots while the line is masked, so the masking rules
    still see the whole line. `redact` itself must keep masking `PASSWD=x` in crons.
    """
    if text is None:
        return None
    kept = []

    def slot(match):
        kept.append(match.group())
        return f"\x00{len(kept) - 1}\x00"

    text = text.replace("\x00", "")
    text, is_alias = _SUDOERS_ALIAS.subn(slot, text, count=1) if _SUDOERS_ALIAS.match(text) else (text, 0)
    if is_alias:
        text = _SUDOERS_NEXT_ALIAS.sub(slot, text)
    text = _SUDOERS_TAG.sub(slot, text)
    return _SLOT.sub(lambda m: kept[int(m.group(1))], redact(text))
