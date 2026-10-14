import unittest

from discover_agent.collectors import server

IP_ADDR = """[
 {"ifindex":1,"ifname":"lo","addr_info":[{"family":"inet","local":"127.0.0.1","prefixlen":8},
                                         {"family":"inet6","local":"::1","prefixlen":128}]},
 {"ifindex":2,"ifname":"eth0","addr_info":[{"family":"inet","local":"10.0.0.11","prefixlen":24},
                                           {"family":"inet6","local":"fe80::1","prefixlen":64,"scope":"link"},
                                           {"family":"inet6","local":"2001:db8::11","prefixlen":64,"scope":"global"}]},
 {"ifindex":3,"ifname":"eth1","addr_info":[]}
]"""

ROUTE_GET = '[{"dst":"1.1.1.1","gateway":"10.0.0.1","dev":"eth0","prefsrc":"10.0.0.11","flags":[],"uid":0,"cache":[]}]'

OS_RELEASE = '''PRETTY_NAME="Ubuntu 24.04.1 LTS"
NAME="Ubuntu"
VERSION_ID="24.04"
'''


class ServerParsersTest(unittest.TestCase):
    def test_parse_ip_addr_skips_loopback_and_link_local(self):
        self.assertEqual(
            server.parse_ip_addr(IP_ADDR),
            [
                {"name": "eth0", "addresses": ["10.0.0.11/24", "2001:db8::11/64"]},
                {"name": "eth1", "addresses": []},
            ],
        )

    def test_parse_route_get_returns_prefsrc(self):
        self.assertEqual(server.parse_route_get(ROUTE_GET), "10.0.0.11")

    def test_parse_route_get_without_route_returns_none(self):
        self.assertIsNone(server.parse_route_get("[]"))

    def test_parse_os_release(self):
        self.assertEqual(server.parse_os_release(OS_RELEASE), "Ubuntu 24.04.1 LTS")

    def test_collect_server_shape_with_fake_runner(self):
        outputs = {
            ("ip", "-j", "addr"): IP_ADDR,
            ("ip", "-j", "route", "get", "1.1.1.1"): ROUTE_GET,
            ("systemd-detect-virt",): "kvm\n",
        }
        files = {"/etc/machine-id": "abc123\n", "/etc/os-release": OS_RELEASE}
        result = server.collect_server(
            runner=lambda cmd, **kw: outputs[tuple(cmd)],
            reader=files.get,
        )
        self.assertEqual(result["machine_id"], "abc123")
        self.assertEqual(result["primary_ip"], "10.0.0.11")
        self.assertEqual(result["os"], "Ubuntu 24.04.1 LTS")
        self.assertEqual(result["virtualization"], "kvm")
        self.assertEqual(result["interfaces"][0]["name"], "eth0")
        for key in ("hostname", "fqdn", "kernel"):
            self.assertTrue(result[key])


if __name__ == "__main__":
    unittest.main()
