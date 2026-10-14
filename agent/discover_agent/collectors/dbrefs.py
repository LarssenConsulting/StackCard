"""Find which MySQL database a site uses by reading its configuration files.

Only names are kept: database, user and host. Passwords and other secrets are
dropped while parsing so they never reach the snapshot.
"""

import os
import re
from urllib.parse import unquote, urlsplit

from discover_agent import util

CONFIG_FILES = ("wp-config.php", ".env", ".env.local", ".env.production")
PARENT_LEVELS = 2
SENSITIVE_MARKERS = ("PASS", "SECRET", "KEY", "TOKEN", "SALT")
MYSQL_SCHEMES = ("mysql", "mariadb")

_WP_DEFINE = re.compile(
    r"""define\(\s*['"](DB_NAME|DB_USER|DB_HOST)['"]\s*,\s*['"]([^'"]*)['"]\s*\)"""
)
_ENV_LINE = re.compile(r"^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$")
_IDENTIFIER = re.compile(r"^[A-Za-z0-9_$.\-]{1,64}$")
_HOST = re.compile(r"^[A-Za-z0-9_.\-:\[\]%/]{1,255}$")


def parse_wp_config(text):
    values = dict(_WP_DEFINE.findall(text))
    if not values.get("DB_NAME"):
        return None
    return {
        "db_name": values["DB_NAME"],
        "db_user": values.get("DB_USER"),
        "db_host": values.get("DB_HOST"),
    }


def _env_value(raw):
    raw = raw.strip()
    if raw[:1] in ("'", '"'):
        end = raw.find(raw[0], 1)
        return raw[1:end] if end != -1 else raw[1:]
    return re.split(r"\s+#", raw, maxsplit=1)[0].strip()


def parse_env(text):
    env = {}
    for line in text.splitlines():
        match = _ENV_LINE.match(line.strip())
        if not match:
            continue
        key = match.group(1)
        if any(marker in key.upper() for marker in SENSITIVE_MARKERS):
            continue
        env[key] = _env_value(match.group(2))
    return env


def parse_database_url(url):
    """Parse mysql://user:password@host:port/name without trusting urlsplit.

    Real-world passwords are often not percent-encoded. urlsplit would stop the
    authority at the first '/', '?' or '#' inside the password and leak the rest
    into the path, so the credentials are cut at the LAST '@' instead.
    """
    scheme, sep, rest = (url or "").partition("://")
    if not sep or not scheme.lower().startswith(MYSQL_SCHEMES):
        return None
    userinfo, at, location = rest.rpartition("@")
    user = unquote(userinfo.split(":", 1)[0]) if at else None
    parts = urlsplit("//" + location)
    name = parts.path.lstrip("/")
    if not name:
        return None
    return {"db_name": unquote(name), "db_user": user or None, "db_host": parts.hostname}


def _is_clean(ref):
    """Drop anything that does not look like a name: a misparse must not ship secrets."""
    if not ref["db_name"] or not _IDENTIFIER.match(ref["db_name"]):
        return False
    if ref["db_user"] and not _IDENTIFIER.match(ref["db_user"]):
        return False
    return not ref["db_host"] or bool(_HOST.match(ref["db_host"]))


def refs_from_env(env):
    connection = env.get("DB_CONNECTION", "mysql").lower()
    if not connection.startswith(MYSQL_SCHEMES):
        return []
    from_url = parse_database_url(env.get("DATABASE_URL", ""))
    if from_url:
        return [from_url]
    name = env.get("DB_DATABASE") or env.get("DB_NAME")
    if not name:
        return []
    return [{
        "db_name": name,
        "db_user": env.get("DB_USERNAME") or env.get("DB_USER"),
        "db_host": env.get("DB_HOST"),
    }]


def _refs_from_file(path, text):
    if os.path.basename(path) == "wp-config.php":
        ref = parse_wp_config(text)
        return [ref] if ref else []
    return refs_from_env(parse_env(text))


def find_db_refs(app_dir, reader=util.read_regular_file):
    if not app_dir:
        return []
    directory = os.path.abspath(app_dir)
    for _ in range(PARENT_LEVELS + 1):
        found_file, refs, seen = False, [], set()
        for name in CONFIG_FILES:
            path = os.path.join(directory, name)
            text = reader(path)
            if text is None:
                continue
            found_file = True
            for ref in filter(_is_clean, _refs_from_file(path, text)):
                key = (ref["db_name"], ref["db_user"], ref["db_host"])
                if key not in seen:
                    seen.add(key)
                    refs.append(dict(ref, source=path))
        if found_file:
            return refs
        parent = os.path.dirname(directory)
        if parent == directory:
            break
        directory = parent
    return []
