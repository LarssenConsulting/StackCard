"""UFW firewall of this server: state, default policies and rules, from `ufw status verbose`."""

import re

from discover_agent import util

# ufw translates its output through Python's gettext, which reads LANGUAGE before LC_ALL.
COMMAND = ["env", "LANGUAGE=C", "LC_ALL=C", "ufw", "status", "verbose"]
COMMENT_MAX = 255

_DEFAULT = re.compile(r"([\w-]+) \((incoming|outgoing|routed)\)")
_RULE = re.compile(
    r"^(?P<to>.+?)\s+(?P<action>(?:ALLOW|DENY|REJECT|LIMIT)(?: (?:IN|OUT|FWD))?)\s+(?P<from>.+?)"
    r"(?:\s+# (?P<comment>.*))?$"
)
_V6 = " (v6)"


def _strip_v6(value):
    """'22/tcp (v6)' -> '22/tcp'; an app profile carries it inside: '80/tcp (Apache (v6))'."""
    if value.endswith(_V6 + ")"):
        return value[: -len(_V6) - 1] + ")", True
    if value.endswith(_V6):
        return value[: -len(_V6)], True
    return value, False


def _rule(line):
    match = _RULE.match(line.rstrip())
    if not match:
        return None
    to, to_v6 = _strip_v6(match["to"].strip())
    source, from_v6 = _strip_v6(match["from"].strip())
    comment = (match["comment"] or "").strip()
    return {
        "to": to,
        "action": match["action"],
        "from": source,
        "ipv6": to_v6 or from_v6,
        "comment": comment[:COMMENT_MAX] or None,
    }


def parse_status(output):
    result = {
        "status": None, "logging": None,
        "default_incoming": None, "default_outgoing": None, "default_routed": None,
        "rules": [],
    }
    in_rules = False
    for line in output.splitlines():
        if in_rules:
            rule = _rule(line) if line.strip() else None
            if rule:
                result["rules"].append(rule)
            continue
        key, _, value = line.partition(":")
        value = value.strip()
        if key == "Status":
            result["status"] = value or None
        elif key == "Logging":
            result["logging"] = value or None
        elif key == "Default":
            for policy, direction in _DEFAULT.findall(value):
                result[f"default_{direction}"] = policy
        elif line.startswith("--"):
            in_rules = True  # the dashes under the "To  Action  From" header
    return result


def collect_firewall(runner=util.run, has_ufw=lambda: util.which("ufw")):
    if not has_ufw():
        return None
    return parse_status(runner(COMMAND))
