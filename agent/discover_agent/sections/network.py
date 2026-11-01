"""Section `network`: listening sockets, MPTCP listeners, nftables, iptables, Fail2Ban, UFW and mitigation services."""

import ast
import ipaddress
import json
import os
import re

from discover_agent import util
from discover_agent.collectors import firewall
from discover_agent.redact import redact
from discover_agent.sections import defaults
from discover_agent.sections.core import Collected, read_source
from discover_agent.sections.tools import run_tool

SS_COMMAND = ["ss", "-ltnupH"]
# Two tables on purpose: `-M` alone hides the Netid column, the only thing telling an MPTCP row
# from a TCP one here, while `-t` makes `ss` print it. `read_mptcp` keeps the mptcp rows.
SS_MPTCP_COMMAND = ["ss", "-ltnMH"]
MPTCP_SYSCTL = "/proc/sys/net/mptcp/enabled"
MPTCP_DIAG_MODULE = "/sys/module/mptcp_diag"
NFT_COMMAND = ["nft", "-j", "list", "ruleset"]
# `iptables-save` follows the system's backend: rules left in the other one would stay unseen.
IPTABLES_SAVE = {"v4": "iptables-save", "v6": "ip6tables-save"}
IPTABLES_LEGACY_SAVE = {"v4": "iptables-legacy-save", "v6": "ip6tables-legacy-save"}
FAIL2BAN_COMMAND = ["fail2ban-client", "-d"]
FAIL2BAN_UNIT = "fail2ban.service"
FAIL2BAN_NFT_TABLE_PREFIX = "f2b-"
FAIL2BAN_JAIL_OPTIONS = ("bantime", "findtime", "maxretry")
FAIL2BAN_ACTION_OPTIONS = ("port", "protocol")
SYSTEMCTL_OK = (0, 1, 3, 4)  # is-enabled / is-active exit non-zero for disabled and inactive units

_PROCESS = re.compile(r'\("((?:[^"\\]|\\.)*)",pid=(\d+)')
_STAT_AFTER_COMM = re.compile(r"\s+\S+\s+(\d+)")
_LOOPBACK_V4 = ipaddress.ip_network("127.0.0.0/8")
_CHAIN_COUNTERS = re.compile(r"\s+\[\d+:\d+\]\Z")
_FAIL2BAN_BAN = re.compile(r"-A f2b-\S+ -s \S+")  # the rule its iptables actions add per banned address
_IPTABLES_COMMENT = re.compile(r'(--comment\s+)("(?:[^"\\]|\\.)*"|\S+)')


def _split_endpoint(text):
    """Return (address, port) with brackets and zone dropped, or None.

    '[::]:80' -> ('::', 80); '*:80' -> ('*', 80); '[fe80::1]%eth0:546' -> ('fe80::1', 546);
    '127.0.0.1%lo:53' -> ('127.0.0.1', 53).
    """
    if text.startswith("["):
        close = text.find("]")
        if close < 0:
            return None
        host, tail = text[1:close], text[close + 1:]
        port = tail.rpartition(":")[2] if ":" in tail else ""
        zone_ok = tail.startswith(":") or tail.startswith("%")
        if not port.isdigit() or not zone_ok:
            return None
        return host.split("%", 1)[0], int(port)
    host, sep, port = text.rpartition(":")
    if not sep or not port.isdigit():
        return None
    return host.split("%", 1)[0], int(port)


def is_public(address):
    """True unless loopback. Returns None (unknown) for an address that cannot be parsed,
    so the central never reads an unparsable address as either public or private."""
    host = address.split("%", 1)[0]
    if host in ("*", ""):
        return True
    try:
        ip = ipaddress.ip_address(host)
    except ValueError:
        return None
    if isinstance(ip, ipaddress.IPv6Address) and ip.ipv4_mapped is not None:
        ip = ip.ipv4_mapped
    return not ip.is_loopback


def _master_pid(pids, ppid_of):
    """The process of the group whose parent is outside it; failing that, the smallest pid."""
    group = set(pids)
    roots = [pid for pid in sorted(group) if ppid_of(pid) not in group]
    return roots[0] if roots else min(group)


def _parse_line(line, ppid_of):
    tokens = line.split(None, 6)
    if len(tokens) < 3:
        return None
    # `ss -t` alone omits the Netid column; with -u it is present.
    has_netid = tokens[0].islower() and len(tokens) > 1 and tokens[1].isupper()
    start = 1 if has_netid else 0
    protocol = tokens[0] if has_netid else "tcp"
    if len(tokens) < start + 4:
        return None
    endpoint = _split_endpoint(tokens[start + 3])
    if endpoint is None:
        return None
    address, port = endpoint
    process = pid = None
    rest = tokens[start + 5] if len(tokens) > start + 5 else ""
    if len(tokens) > start + 6:
        rest += " " + tokens[start + 6]
    found = _PROCESS.findall(rest)
    if found:
        pid = _master_pid([int(p) for _, p in found], ppid_of)
        process = next(name for name, p in found if int(p) == pid)
    return {
        "protocol": protocol,
        "address": address,
        "port": port,
        "process": process,
        "pid": pid,
        "public": is_public(address),
    }


def _sort_key(record):
    return (record["protocol"], record["port"], record["address"], record["process"] or "", record["pid"] or 0)


def parse_sockets(output, ppid_of=lambda pid: None):
    records = []
    for line in output.splitlines():
        record = _parse_line(line, ppid_of) if line.strip() else None
        if record is not None and record not in records:
            records.append(record)
    return sorted(records, key=_sort_key)


_LIVE_COUNTER_KEYS = ("packets", "bytes")
_LIVE_QUOTA_KEYS = ("packets", "bytes", "used")
_LIVE_ELEM_KEYS = ("expires", "timeout")
_LIVE_KEYS = {"counter": _LIVE_COUNTER_KEYS, "quota": _LIVE_QUOTA_KEYS, "elem": _LIVE_ELEM_KEYS, "last": ("used",)}


def _clean_nft(node, parent=None):
    """Mask `comment` strings and drop live values (counters, quotas, `last`, set-element expiry).

    Live values would change the sha256 on every send. The rule structure stays
    (`{"counter": {}}`), as do named counters' names, `handle` and `metainfo`.
    """
    if isinstance(node, dict):
        drop = _LIVE_KEYS.get(parent, ())
        out = {}
        for key, value in node.items():
            if key in drop:
                continue
            if key == "comment" and isinstance(value, str):
                out[key] = redact(value)
            else:
                out[key] = _clean_nft(value, key)
        return out
    if isinstance(node, list):
        return [_clean_nft(item, parent) for item in node]
    return node


def read_nft(runner):
    ruleset = _clean_nft(json.loads(run_tool(runner, NFT_COMMAND)))
    # The elements of Fail2Ban's sets are the addresses banned right now: live values.
    for entity in ruleset.get("nftables", []) if isinstance(ruleset, dict) else []:
        found = entity.get("set") if isinstance(entity, dict) else None
        if isinstance(found, dict) and str(found.get("table", "")).startswith(FAIL2BAN_NFT_TABLE_PREFIX):
            found.pop("elem", None)
    return ruleset


def _fail2ban_stream(text):
    """The commands of `fail2ban-client -d`, one Python list per line; anything else is skipped."""
    for line in text.splitlines():
        if line.startswith("["):
            try:
                command = ast.literal_eval(line)
            except (ValueError, SyntaxError, MemoryError, RecursionError):
                continue
            if isinstance(command, list) and command:
                yield command


def _fail2ban_jail(name, backend):
    return {"name": name, "backend": backend, "bantime": None, "findtime": None, "maxretry": None,
            "ignoreip": set(), "logpaths": set(), "actions": {}}


def parse_fail2ban_dump(text):
    """Configuration of the enabled jails. Filter regexes and action options stay out: an action's
    options can hold an API key, so only its name, port and protocol are kept."""
    jails, errors = {}, []

    def action_option(jail, action, option, value):
        entry = jail["actions"].get(action)
        if entry is not None and option in FAIL2BAN_ACTION_OPTIONS:
            entry[option] = str(value)

    for command in _fail2ban_stream(text):
        verb = command[0]
        if verb == "config-error" and len(command) > 1:
            errors.append(redact(str(command[1])))
        elif verb == "add" and len(command) > 2:
            jails[str(command[1])] = _fail2ban_jail(str(command[1]), str(command[2]))
        jail = jails.get(str(command[1])) if len(command) > 3 else None
        if jail is None:
            continue
        if verb == "multi-set" and len(command) > 4 and command[2] == "action" and isinstance(command[4], list):
            for pair in command[4]:
                if isinstance(pair, list) and len(pair) == 2:
                    action_option(jail, str(command[3]), pair[0], pair[1])
        elif verb == "set":
            option = command[2]
            if option in FAIL2BAN_JAIL_OPTIONS:
                jail[option] = str(command[3])
            elif option == "addignoreip":
                jail["ignoreip"].update(str(value) for value in command[3:])
            elif option == "addlogpath":
                jail["logpaths"].add(str(command[3]))
            elif option == "addaction":
                jail["actions"].setdefault(str(command[3]), {"name": str(command[3]), "port": None, "protocol": None})
            elif option == "action" and len(command) > 5:
                action_option(jail, str(command[3]), command[4], command[5])
    result = []
    for name in sorted(jails):
        jail = jails[name]
        jail["ignoreip"], jail["logpaths"] = sorted(jail["ignoreip"]), sorted(jail["logpaths"])
        jail["actions"] = [jail["actions"][key] for key in sorted(jail["actions"])]
        result.append(jail)
    return result, errors


def read_fail2ban(runner):
    """Works with the daemon stopped: `-d` reads the configuration files, not the server."""
    jails, errors = parse_fail2ban_dump(run_tool(runner, FAIL2BAN_COMMAND))
    return {
        "enabled": _state(runner, "is-enabled", FAIL2BAN_UNIT) or "not-found",
        "active": _state(runner, "is-active", FAIL2BAN_UNIT) or "inactive",
        "jails": jails,
        "config_errors": errors,
    }


def parse_iptables_save(text):
    """The lines of `iptables-save`, in order: order decides which rule applies.

    Chain counters, dated comment lines and Fail2Ban's bans are live values. Only `--comment` is free text:
    `redact` on a whole rule would read `-p tcp` as a password option.
    """
    lines = []
    for raw in text.splitlines():
        line = raw.strip()
        if line and not line.startswith("#") and not _FAIL2BAN_BAN.match(line):
            line = _CHAIN_COUNTERS.sub("", line)
            lines.append(_IPTABLES_COMMENT.sub(lambda m: m.group(1) + redact(m.group(2)), line))
    return lines


def read_iptables(errors, name, commands, runner):
    return {
        family: read_source(errors, f"{name}.{family}", lambda cmd=cmd: parse_iptables_save(run_tool(runner, [cmd])))
        for family, cmd in commands.items()
    }


def read_mptcp(runner, ppid_of, path_exists):
    """MPTCP listeners. No row is only "none" if `ss` could ask: it needs the mptcp_diag module,
    which `ProtectKernelModules` keeps from loading in the service."""
    records = parse_sockets(run_tool(runner, SS_MPTCP_COMMAND), ppid_of)
    listeners = [record for record in records if record["protocol"] == "mptcp"]
    if not listeners and path_exists(MPTCP_SYSCTL) and not path_exists(MPTCP_DIAG_MODULE):
        raise util.CollectorError("mptcp_diag non chargé")
    return listeners


def read_ufw(runner, has_ufw):
    if not has_ufw():
        raise util.NotInstalled("ufw")
    return firewall.parse_status(runner(firewall.COMMAND))


def _state(runner, verb, name):
    out = run_tool(runner, ["systemctl", verb, name], ok_codes=SYSTEMCTL_OK).strip()
    return out.splitlines()[0] if out else ""


def read_services(runner, services):
    result = []
    for name in services:
        # An unknown unit prints nothing on stdout for is-enabled, and `util.run` hides stderr and the
        # exit code, so empty stdout is reported as the spec's "not-found" (a broken systemctl too).
        enabled = _state(runner, "is-enabled", name) or "not-found"
        active = _state(runner, "is-active", name) or "inactive"
        result.append({"name": name, "enabled": enabled, "active": active})
    return result


def _proc_ppid(reader):
    def ppid_of(pid):
        text = reader(f"/proc/{pid}/stat") or ""
        # The command name may contain ")" or spaces: fields start after the last ")".
        match = _STAT_AFTER_COMM.match(text, text.rfind(")") + 1) if ")" in text else None
        return int(match.group(1)) if match else None

    return ppid_of


def collect(runner=util.run_c, reader=util.read_text, ppid_of=None, has_ufw=lambda: util.which("ufw"),
            services=defaults.MITIGATION_SERVICES, path_exists=os.path.exists):
    ppid_of = ppid_of or _proc_ppid(reader)
    errors = {}
    data = {
        "sockets": read_source(
            errors, "sockets", lambda: parse_sockets(run_tool(runner, SS_COMMAND), ppid_of)
        ),
        "mptcp_listeners": read_source(
            errors, "mptcp_listeners", lambda: read_mptcp(runner, ppid_of, path_exists)
        ),
        "nft": read_source(errors, "nft", lambda: read_nft(runner)),
        "iptables": read_iptables(errors, "iptables", IPTABLES_SAVE, runner),
        "iptables_legacy": read_iptables(errors, "iptables_legacy", IPTABLES_LEGACY_SAVE, runner),
        "fail2ban": read_source(errors, "fail2ban", lambda: read_fail2ban(runner)),
        "ufw": read_source(errors, "ufw", lambda: read_ufw(runner, has_ufw)),
        "services": read_source(errors, "services", lambda: read_services(runner, services)),
    }
    return Collected(data, errors)
