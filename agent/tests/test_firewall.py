import unittest

from discover_agent import util
from discover_agent.collectors import firewall

ACTIVE = """\
Status: active
Logging: on (low)
Default: deny (incoming), allow (outgoing), disabled (routed)
New profiles: skip

To                         Action      From
--                         ------      ----
22/tcp                     LIMIT IN    Anywhere
80,443/tcp (Apache Full)   ALLOW IN    Anywhere
3306/tcp                   ALLOW IN    10.0.0.5                   # app01 vers MySQL
Anywhere on eth1           ALLOW IN    192.168.1.0/24
25/tcp                     DENY OUT    Anywhere
22/tcp (v6)                LIMIT IN    Anywhere (v6)
80,443/tcp (Apache Full (v6)) ALLOW IN    Anywhere (v6)

"""


def collect(output=ACTIVE, **overrides):
    calls = []

    def runner(cmd, **kw):
        calls.append(cmd)
        return output

    values = dict(runner=runner, has_ufw=lambda: True)
    values.update(overrides)
    return firewall.collect_firewall(**values), calls


class CollectFirewallTest(unittest.TestCase):
    def test_no_ufw_means_no_section(self):
        result, calls = collect(has_ufw=lambda: False)
        self.assertIsNone(result)
        self.assertEqual(calls, [])

    def test_output_is_read_untranslated(self):
        _, calls = collect()
        self.assertEqual(calls, [["env", "LANGUAGE=C", "LC_ALL=C", "ufw", "status", "verbose"]])

    def test_state_and_default_policies(self):
        result, _ = collect()
        self.assertEqual(
            {k: v for k, v in result.items() if k != "rules"},
            {
                "status": "active", "logging": "on (low)",
                "default_incoming": "deny", "default_outgoing": "allow", "default_routed": "disabled",
            },
        )

    def test_rules_in_order_with_ipv6_and_comments(self):
        rules = collect()[0]["rules"]
        self.assertEqual(len(rules), 7)
        self.assertEqual(rules[0], {"to": "22/tcp", "action": "LIMIT IN", "from": "Anywhere", "ipv6": False, "comment": None})
        self.assertEqual(rules[1]["to"], "80,443/tcp (Apache Full)")
        self.assertEqual(rules[2], {
            "to": "3306/tcp", "action": "ALLOW IN", "from": "10.0.0.5", "ipv6": False, "comment": "app01 vers MySQL",
        })
        self.assertEqual(rules[3]["to"], "Anywhere on eth1")
        self.assertEqual(rules[3]["from"], "192.168.1.0/24")
        self.assertEqual(rules[4]["action"], "DENY OUT")
        self.assertEqual(rules[5], {"to": "22/tcp", "action": "LIMIT IN", "from": "Anywhere", "ipv6": True, "comment": None})
        # A destination longer than its 26-character column pushes the others right.
        self.assertEqual(rules[6]["to"], "80,443/tcp (Apache Full)")
        self.assertTrue(rules[6]["ipv6"])

    def test_inactive_firewall_has_no_rules(self):
        result, _ = collect(output="Status: inactive\n")
        self.assertEqual(result["status"], "inactive")
        self.assertIsNone(result["default_incoming"])
        self.assertEqual(result["rules"], [])

    def test_long_comment_is_cut(self):
        result, _ = collect(output=ACTIVE.replace("app01 vers MySQL", "x" * 400))
        self.assertEqual(len(result["rules"][2]["comment"]), firewall.COMMENT_MAX)

    def test_command_failure_propagates(self):
        def runner(cmd, **kw):
            raise util.CommandError("ufw exited 1: ERROR: You need to be root to run this script")

        with self.assertRaises(util.CommandError):
            firewall.collect_firewall(runner=runner, has_ufw=lambda: True)
