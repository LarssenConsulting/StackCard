"""MySQL/MariaDB: databases, accounts and privileges. Never reads password hashes."""

import os
import re

from discover_agent import util

EXTRA_DEFAULTS = "/etc/discover-agent/mysql.cnf"
SYSTEM_SCHEMAS = ("mysql", "sys", "information_schema", "performance_schema")

_SYSTEM_LIST = ", ".join(f"'{name}'" for name in SYSTEM_SCHEMAS)
SQL_VERSION = "SELECT VERSION()"
SQL_DATABASES = (
    "SELECT s.SCHEMA_NAME, COALESCE(SUM(t.DATA_LENGTH + t.INDEX_LENGTH), 0) "
    "FROM information_schema.SCHEMATA s "
    "LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = s.SCHEMA_NAME "
    f"WHERE s.SCHEMA_NAME NOT IN ({_SYSTEM_LIST}) "
    "GROUP BY s.SCHEMA_NAME ORDER BY s.SCHEMA_NAME"
)
SQL_USERS = "SELECT user, host FROM mysql.user ORDER BY user, host"
SQL_SCHEMA_GRANTS = (
    "SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES"
)
SQL_GLOBAL_GRANTS = "SELECT GRANTEE, PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES"

_GRANTEE = re.compile(r"^'(.*)'@'(.*)'$")

HELP = (
    "MySQL inaccessible ({error}). Créez un compte en lecture seule et déclarez-le "
    f"dans {EXTRA_DEFAULTS} (voir docs/install-agent.md)."
)


def parse_tsv(text):
    return [line.split("\t") for line in text.splitlines() if line]


def _grantee(value):
    match = _GRANTEE.match(value)
    return (match.group(1), match.group(2)) if match else (value, "")


def _aggregate_grants(schema_rows, global_rows):
    grants = {}
    for grantee, schema, privilege in schema_rows:
        user, host = _grantee(grantee)
        key = (user, host, schema.replace("\\_", "_").replace("\\%", "%"), False)
        grants.setdefault(key, set()).add(privilege)
    for grantee, privilege in global_rows:
        if privilege == "USAGE":
            continue
        user, host = _grantee(grantee)
        grants.setdefault((user, host, None, True), set()).add(privilege)
    return [
        {"user": user, "host": host, "database": database,
         "privileges": sorted(privileges), "global": is_global}
        for (user, host, database, is_global), privileges in grants.items()
    ]


def collect_mysql(runner=util.run, has_client=lambda: util.which("mysql"), exists=os.path.exists):
    if not has_client():
        return None
    base = ["mysql"]
    if exists(EXTRA_DEFAULTS):
        base.append(f"--defaults-extra-file={EXTRA_DEFAULTS}")
    base += ["--protocol=socket", "-N", "-B", "-e"]

    def query(sql):
        return runner(base + [sql])

    try:
        version = query(SQL_VERSION).strip()
        databases = parse_tsv(query(SQL_DATABASES))
        users = parse_tsv(query(SQL_USERS))
        schema_grants = parse_tsv(query(SQL_SCHEMA_GRANTS))
        global_grants = parse_tsv(query(SQL_GLOBAL_GRANTS))
    except util.CommandError as exc:
        raise util.CollectorError(HELP.format(error=exc)) from exc

    return {
        "version": version,
        "databases": [{"name": name, "size_bytes": int(size)} for name, size in databases],
        "users": [{"user": user, "host": host} for user, host in users],
        "grants": _aggregate_grants(schema_grants, global_grants),
    }
