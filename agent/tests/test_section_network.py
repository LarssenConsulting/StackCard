import json
import unittest

from discover_agent import util
from discover_agent.sections import network

SS = """\
tcp   LISTEN 0 511  0.0.0.0:80      0.0.0.0:*  users:(("apache2",pid=30,fd=4),("apache2",pid=10,fd=4))
tcp   LISTEN 0 4096     [::]:443       [::]:*  users:(("apache2",pid=10,fd=6))
tcp   LISTEN 0 128         *:8080         *:*  users:(("node",pid=77,fd=19))
udp   UNCONN 0 0  127.0.0.1%lo:53   0.0.0.0:*  users:(("systemd-resolve",pid=5,fd=13))
tcp   LISTEN 0 128    127.0.0.5:3306    0.0.0.0:*
tcp   LISTEN 0 128        [::1]:631         [::]:*
"""
UFW = """\
Status: active
Logging: on (low)
Default: deny (incoming), allow (outgoing), disabled (routed)
New profiles: skip

To                         Action      From
--                         ------      ----
22/tcp                     ALLOW IN    Anywhere
"""
NFT = {"nftables": [{"rule": {"family": "inet", "table": "cve98432", "comment": "block x", "expr": [{"drop": None}]}}]}


def make(ss=SS, mptcp="", nft=None, ufw=UFW, enabled=None, active=None, has_ufw=True, **overrides):
    nft = json.dumps(NFT) if nft is None else nft
    enabled = {} if enabled is None else enabled
    active = {} if active is None else active

    def result(value):
        if isinstance(value, Exception):
            raise value
        return value

    def runner(cmd, **kw):
        if cmd[0] == "ss":
            return result(mptcp if "M" in cmd[1] else ss)
        if cmd[0] == "nft":
            return result(nft)
        if cmd[0] == "systemctl":
            assert kw.get("ok_codes") == (0, 1, 3, 4)
            return result((enabled if cmd[1] == "is-enabled" else active).get(cmd[2], ""))
        if cmd[-3:] == ["ufw", "status", "verbose"]:
            return result(ufw)
        raise AssertionError(cmd)

    values = dict(
        runner=runner,
        path_exists=lambda path: False,
        ppid_of={30: 10, 10: 1, 77: 1, 5: 1}.get,
        has_ufw=lambda: has_ufw,
        services=("cve98432-filter.service",),
    )
    values.update(overrides)
    return network.collect(**values)


def by_port(collected, port, proto="tcp"):
    return [s for s in collected.data["sockets"] if s["port"] == port and s["protocol"] == proto]


class SocketsTest(unittest.TestCase):
    def test_parse_atypical_sockets(self):
        c = make()
        self.assertNotIn("sockets", c.source_errors)
        self.assertTrue(by_port(c, 80)[0]["public"])
        self.assertEqual(by_port(c, 443)[0]["address"], "::")
        self.assertTrue(by_port(c, 443)[0]["public"])
        star = by_port(c, 8080)[0]
        self.assertEqual(star["address"], "*")
        self.assertTrue(star["public"])
        lo = by_port(c, 53, "udp")[0]
        self.assertEqual(lo["address"], "127.0.0.1")
        self.assertFalse(lo["public"])
        bare = by_port(c, 3306)[0]
        self.assertFalse(bare["public"])  # 127.0.0.0/8
        self.assertIsNone(bare["process"])
        self.assertIsNone(bare["pid"])
        self.assertFalse(by_port(c, 631)[0]["public"])

    def test_zoned_and_mapped_addresses(self):
        ss = (
            "tcp LISTEN 0 1 [fe80::1]%eth0:546 [::]:* users:((\"a\",pid=1,fd=1))\n"
            "udp UNCONN 0 0 [::1]%lo:53 [::]:*\n"
            "tcp LISTEN 0 1 [::ffff:127.0.0.1]:80 [::]:*\n"
            "udp UNCONN 0 0 0.0.0.0%eth0:68 0.0.0.0:*\n"
            "tcp LISTEN 0 1 weird:90 0.0.0.0:*\n"
        )
        got = {(s["protocol"], s["port"]): s for s in make(ss=ss).data["sockets"]}
        self.assertEqual((got["tcp", 546]["address"], got["tcp", 546]["public"]), ("fe80::1", True))
        self.assertEqual((got["udp", 53]["address"], got["udp", 53]["public"]), ("::1", False))
        self.assertEqual((got["tcp", 80]["address"], got["tcp", 80]["public"]), ("::ffff:127.0.0.1", False))
        self.assertEqual((got["udp", 68]["address"], got["udp", 68]["public"]), ("0.0.0.0", True))
        self.assertIsNone(got["tcp", 90]["public"])

    def test_process_comes_from_master_entry(self):
        line = 'tcp LISTEN 0 1 0.0.0.0:81 0.0.0.0:* users:(("worker",pid=20,fd=1),("master",pid=10,fd=1))\n'
        c = make(ss=line, ppid_of={20: 10, 10: 1}.get)
        self.assertEqual((c.data["sockets"][0]["process"], c.data["sockets"][0]["pid"]), ("master", 10))

    def test_master_pid_only(self):
        sock = by_port(make(), 80)[0]
        self.assertEqual(sock["process"], "apache2")
        self.assertEqual(sock["pid"], 10)

    def test_master_pid_falls_back_to_smallest(self):
        line = 'tcp LISTEN 0 1 0.0.0.0:81 0.0.0.0:* users:(("x",pid=9,fd=1),("x",pid=4,fd=1))\n'
        c = make(ss=line, ppid_of={9: 4, 4: 9}.get)
        self.assertEqual(c.data["sockets"][0]["pid"], 4)

    def test_sockets_are_sorted(self):
        sockets = make().data["sockets"]
        keys = [(s["protocol"], s["port"], s["address"]) for s in sockets]
        self.assertEqual(keys, sorted(keys))
        reversed_input = "\n".join(reversed(SS.splitlines())) + "\n"
        self.assertEqual(make(ss=reversed_input).data["sockets"], sockets)

    def test_ss_failure_is_null(self):
        c = make(ss=util.CommandError("ss exited 1"))
        self.assertIsNone(c.data["sockets"])
        self.assertIn("sockets", c.source_errors)


class MptcpTest(unittest.TestCase):
    def test_no_mptcp_is_empty_list(self):
        c = make(mptcp="")
        self.assertEqual(c.data["mptcp_listeners"], [])
        self.assertNotIn("mptcp_listeners", c.source_errors)

    def test_listeners(self):
        c = make(mptcp="mptcp LISTEN 0 4096 0.0.0.0:443 0.0.0.0:*\n")
        self.assertEqual(c.data["mptcp_listeners"][0]["port"], 443)

    def test_mptcp_failure_is_null(self):
        c = make(mptcp=util.CommandError("ss exited 1: bad option"))
        self.assertIsNone(c.data["mptcp_listeners"])
        self.assertIn("mptcp_listeners", c.source_errors)
        self.assertIsNotNone(c.data["sockets"])


class NftTest(unittest.TestCase):
    def test_nft_absent(self):
        c = make(nft=util.CommandError("command not found: nft"))
        self.assertIsNone(c.data["nft"])
        self.assertEqual(c.source_errors["nft"], "not_installed")
        self.assertIsNotNone(c.data["sockets"])
        self.assertIsNotNone(c.data["ufw"])

    def test_nft_comment_redacted(self):
        ruleset = {"nftables": [{"rule": {"table": "t", "comment": "PGPASSWORD=hunter2 job", "handle": 3}},
                                {"rule": {"table": "PGPASSWORD=keepme", "comment": "ok"}}]}
        c = make(nft=json.dumps(ruleset))
        rules = c.data["nft"]["nftables"]
        self.assertNotIn("hunter2", rules[0]["rule"]["comment"])
        self.assertEqual(rules[0]["rule"]["table"], "t")
        self.assertEqual(rules[0]["rule"]["handle"], 3)
        self.assertEqual(rules[1]["rule"]["table"], "PGPASSWORD=keepme")  # only comments are masked
        self.assertEqual(rules[1]["rule"]["comment"], "ok")

    def test_nft_invalid_json_is_an_error(self):
        c = make(nft="not json")
        self.assertIsNone(c.data["nft"])
        self.assertIn("nft", c.source_errors)
        self.assertNotEqual(c.source_errors["nft"], "not_installed")


class NftStabilityTest(unittest.TestCase):
    @staticmethod
    def ruleset(packets, expires, drop="drop"):
        return json.dumps({"nftables": [
            {"metainfo": {"version": "1.0.9"}},
            {"rule": {"handle": 4, "expr": [{"counter": {"packets": packets, "bytes": packets * 60}}, {drop: None}]}},
            {"counter": {"name": "c1", "handle": 7, "packets": packets, "bytes": 5}},
            {"quota": {"name": "q", "val": 100, "used": packets}},
            {"set": {"name": "s", "timeout": 3600, "elem": [{"elem": {"val": "1.2.3.4", "expires": expires, "timeout": 3600}}]}},
        ]})

    def test_live_values_do_not_change_sha(self):
        from discover_agent.sections.core import canonical_sha256
        a = make(nft=self.ruleset(1, 100)).data["nft"]
        b = make(nft=self.ruleset(99, 5)).data["nft"]
        self.assertEqual(canonical_sha256(a), canonical_sha256(b))
        self.assertEqual(a["nftables"][1]["rule"]["expr"][0], {"counter": {}})
        self.assertEqual(a["nftables"][2]["counter"], {"name": "c1", "handle": 7})
        self.assertEqual(a["nftables"][0]["metainfo"], {"version": "1.0.9"})
        self.assertEqual(a["nftables"][1]["rule"]["handle"], 4)
        self.assertEqual(a["nftables"][4]["set"]["timeout"], 3600)
        self.assertEqual(a["nftables"][4]["set"]["elem"][0]["elem"], {"val": "1.2.3.4"})

    def test_changed_rule_changes_sha(self):
        from discover_agent.sections.core import canonical_sha256
        a = make(nft=self.ruleset(1, 100)).data["nft"]
        b = make(nft=self.ruleset(1, 100, drop="accept")).data["nft"]
        self.assertNotEqual(canonical_sha256(a), canonical_sha256(b))


class UfwTest(unittest.TestCase):
    def test_parsed(self):
        ufw = make().data["ufw"]
        self.assertEqual(ufw["status"], "active")
        self.assertEqual(ufw["rules"][0]["to"], "22/tcp")

    def test_ufw_absent_differs_from_failure(self):
        absent = make(has_ufw=False)
        self.assertIsNone(absent.data["ufw"])
        self.assertEqual(absent.source_errors["ufw"], "not_installed")
        failed = make(ufw=util.CommandError("ufw exited 1: ERROR: You need to be root"))
        self.assertIsNone(failed.data["ufw"])
        self.assertIn("root", failed.source_errors["ufw"])


class ServicesTest(unittest.TestCase):
    def test_service_states(self):
        name = "cve98432-filter.service"
        c = make(enabled={name: "disabled\n"}, active={name: "inactive\n"})
        self.assertEqual(c.data["services"], [{"name": name, "enabled": "disabled", "active": "inactive"}])
        c = make(enabled={name: "enabled\n"}, active={name: "active\n"})
        self.assertEqual(c.data["services"][0]["enabled"], "enabled")
        self.assertEqual(c.data["services"][0]["active"], "active")

    def test_unknown_service(self):
        c = make()  # empty stdout for both
        self.assertEqual(
            c.data["services"], [{"name": "cve98432-filter.service", "enabled": "not-found", "active": "inactive"}]
        )

    def test_systemctl_absent(self):
        def runner(cmd, **kw):
            if cmd[0] == "systemctl":
                raise util.CommandError("command not found: systemctl")
            return ""
        c = make(runner=runner)
        self.assertIsNone(c.data["services"])
        self.assertEqual(c.source_errors["services"], "not_installed")


MPTCP_MIXED = """\
tcp   LISTEN 0 511  0.0.0.0:80   0.0.0.0:*
mptcp LISTEN 0 4096 0.0.0.0:443  0.0.0.0:*
tcp   LISTEN 0 128  [::]:22      [::]:*
"""
MPTCP_SYSCTL = "/proc/sys/net/mptcp/enabled"
MPTCP_DIAG = "/sys/module/mptcp_diag"


class MptcpListenersTest(unittest.TestCase):
    def test_tcp_rows_of_the_two_tables_are_dropped(self):
        self.assertEqual(network.SS_MPTCP_COMMAND, ["ss", "-ltnMH"])
        c = make(mptcp=MPTCP_MIXED)
        self.assertEqual(
            [(s["protocol"], s["port"]) for s in c.data["mptcp_listeners"]], [("mptcp", 443)]
        )
        self.assertNotIn("mptcp_listeners", c.source_errors)

    def test_a_row_needs_no_diag_check(self):
        # A listener proves the module is loaded, whatever /sys says.
        c = make(mptcp=MPTCP_MIXED, path_exists={MPTCP_SYSCTL}.__contains__)
        self.assertEqual(len(c.data["mptcp_listeners"]), 1)
        self.assertNotIn("mptcp_listeners", c.source_errors)

    def test_kernel_with_mptcp_but_no_diag_module_is_unknown(self):
        c = make(mptcp="tcp LISTEN 0 511 0.0.0.0:80 0.0.0.0:*\n", path_exists={MPTCP_SYSCTL}.__contains__)
        self.assertIsNone(c.data["mptcp_listeners"])
        self.assertEqual(c.source_errors["mptcp_listeners"], "mptcp_diag non chargé")

    def test_kernel_with_mptcp_and_diag_module_is_empty(self):
        c = make(mptcp="", path_exists={MPTCP_SYSCTL, MPTCP_DIAG}.__contains__)
        self.assertEqual(c.data["mptcp_listeners"], [])
        self.assertNotIn("mptcp_listeners", c.source_errors)

    def test_kernel_without_mptcp_is_empty(self):
        c = make(mptcp="", path_exists=lambda path: False)
        self.assertEqual(c.data["mptcp_listeners"], [])
        self.assertNotIn("mptcp_listeners", c.source_errors)


class NftLiveValuesTest(unittest.TestCase):
    def test_last_used_is_dropped_from_the_hash(self):
        def ruleset(used):
            return json.dumps({"nftables": [{"rule": {"expr": [{"last": {"used": used}}, {"drop": None}]}}]})

        first, second = make(nft=ruleset(10)), make(nft=ruleset(99999))
        self.assertEqual(first.data["nft"], second.data["nft"])
        self.assertEqual(first.data["nft"]["nftables"][0]["rule"]["expr"][0], {"last": {}})


if __name__ == "__main__":
    unittest.main()
