import unittest

from discover_agent import util
from discover_agent.collectors import node

SS = """LISTEN 0      511          127.0.0.1:3000      0.0.0.0:*    users:(("node",pid=1234,fd=20))
LISTEN 0      511              [::1]:3000         [::]:*    users:(("node",pid=1234,fd=21))
LISTEN 0      4096           0.0.0.0:4000      0.0.0.0:*    users:(("node",pid=2222,fd=3),("node",pid=2223,fd=3))
LISTEN 0      80                   *:3306            *:*    users:(("mariadbd",pid=900,fd=21))
LISTEN 0      511            0.0.0.0:5173      0.0.0.0:*    users:(("next-server (v1",pid=3333,fd=19))
"""

EXES = {
    1234: "/usr/bin/node",
    2222: "/home/front/.nvm/versions/node/v22.11.0/bin/node",
    2223: "/home/front/.nvm/versions/node/v22.11.0/bin/node",
    900: "/usr/sbin/mariadbd",
    3333: "/usr/bin/node",
}

PROCS = {
    1234: {"user": "api", "group": "api", "cwd": "/srv/api", "process_manager": "systemd:api.service"},
    2222: {"user": "front", "group": "front", "cwd": "/home/front/app", "process_manager": "pm2"},
    2223: {"user": "front", "group": "front", "cwd": "/home/front/app", "process_manager": "pm2"},
    3333: {"user": "web", "group": "web", "cwd": "/srv/web", "process_manager": "systemd:web.service"},
    900: {"user": "mysql", "group": "mysql", "cwd": "/", "process_manager": "systemd:mariadb.service"},
}


def runner(cmd, **kw):
    if cmd[0] == "ss":
        return SS
    if cmd[0] == "dpkg-query":
        return "20.18.0-1nodesource1"
    raise util.CommandError(cmd[0])


def collect(**overrides):
    values = dict(
        runner=runner,
        exe_of=EXES.get,
        proc_info=PROCS.get,
        read_package=lambda cwd: {"/srv/api": ("api", "1.4.0")}.get(cwd, (None, None)),
        detect_technologies=lambda cwd: [{"name": "Express", "version": "4.21.1"}] if cwd == "/srv/api" else [],
    )
    values.update(overrides)
    return node.collect_node(**values)["apps"]


class CollectNodeTest(unittest.TestCase):
    def test_every_listening_node_process_is_an_app(self):
        apps = collect()
        self.assertEqual([a["app_dir"] for a in apps], ["/srv/api", "/home/front/app", "/srv/web"])

    def test_one_app_with_all_its_addresses(self):
        api = collect()[0]
        self.assertEqual(api, {
            "name": "api", "version": "1.4.0", "node_version": "20.18.0",
            "listen": [{"address": "127.0.0.1", "port": 3000}, {"address": "::1", "port": 3000}],
            "user": "api", "group": "api", "app_dir": "/srv/api",
            "process_manager": "systemd:api.service",
            "technologies": [{"name": "Express", "version": "4.21.1"}],
        })

    def test_node_version_from_an_nvm_path_without_dpkg(self):
        front = collect()[1]
        self.assertEqual(front["node_version"], "22.11.0")
        self.assertEqual(front["listen"], [{"address": "0.0.0.0", "port": 4000}])
        self.assertEqual(front["process_manager"], "pm2")

    def test_a_renamed_process_is_still_node_through_its_executable(self):
        self.assertEqual(collect()[2]["listen"], [{"address": "0.0.0.0", "port": 5173}])

    def test_unknown_system_node_version(self):
        def no_dpkg(cmd, **kw):
            if cmd[0] == "dpkg-query":
                raise util.CommandError("dpkg-query exited 1")
            return runner(cmd)

        self.assertIsNone(collect(runner=no_dpkg)[0]["node_version"])

    def test_vanished_process_is_skipped(self):
        self.assertEqual(collect(proc_info=lambda pid: None), [])


class ParsersTest(unittest.TestCase):
    def test_sockets(self):
        self.assertEqual(node.parse_ss_sockets(SS)[2], ("0.0.0.0", 4000, 2223))
        self.assertEqual(node.parse_ss_sockets(SS)[1], ("::1", 3000, 1234))

    def test_a_process_name_cannot_point_the_socket_at_another_pid(self):
        # A process named 'x",pid=1234,fd=3)' tries to pass its port off as the api's.
        line = 'LISTEN 0 511 0.0.0.0:6000 0.0.0.0:* users:(("x",pid=1234,fd=3),("",pid=6666,fd=9))\n'
        self.assertEqual(node.parse_ss_sockets(line), [("0.0.0.0", 6000, 6666)])

    def test_versions(self):
        self.assertEqual(node.node_version_from_path("/usr/local/n/versions/node/18.20.4/bin/node"), "18.20.4")
        self.assertEqual(node.node_version_from_path("/home/u/.nvm/versions/node/v22.11.0/bin/node"), "22.11.0")
        self.assertIsNone(node.node_version_from_path("/usr/bin/node"))
        self.assertEqual(node.parse_dpkg_version("1:20.18.0-1nodesource1"), "20.18.0")
        self.assertEqual(node.parse_dpkg_version("18.19.1+dfsg-6ubuntu5"), "18.19.1")
        self.assertIsNone(node.parse_dpkg_version(""))


class SystemNodeVersionTest(unittest.TestCase):
    def apps(self, exe, dpkg=lambda: "20.18.0-1nodesource1"):
        calls = []

        def run(cmd, **kw):
            calls.append(cmd[0])
            if cmd[0] == "ss":
                return 'LISTEN 0 511 127.0.0.1:3000 0.0.0.0:* users:(("node",pid=1234,fd=20))\n'
            return dpkg()

        apps = collect(runner=run, exe_of=lambda pid: exe)
        return apps, calls

    def test_deleted_dpkg_binary_is_kept_without_a_version(self):
        apps, calls = self.apps("/usr/bin/node (deleted)")
        self.assertEqual([a["app_dir"] for a in apps], ["/srv/api"])
        self.assertIsNone(apps[0]["node_version"])
        self.assertNotIn("dpkg-query", calls)

    def test_deleted_binary_under_nvm_keeps_the_version_of_its_path(self):
        apps, _ = self.apps("/home/u/.nvm/versions/node/v22.11.0/bin/node (deleted)")
        self.assertEqual(apps[0]["node_version"], "22.11.0")

    def test_usr_local_bin_node_is_not_the_dpkg_package(self):
        apps, calls = self.apps("/usr/local/bin/node")
        self.assertEqual(len(apps), 1)
        self.assertIsNone(apps[0]["node_version"])
        self.assertNotIn("dpkg-query", calls)

    def test_nodejs_binary_uses_dpkg(self):
        apps, _ = self.apps("/usr/bin/nodejs")
        self.assertEqual(apps[0]["node_version"], "20.18.0")

    def test_section_and_collector_share_the_constants(self):
        from discover_agent.sections import node_apps

        self.assertIs(node_apps.node.DPKG_NODE, node.DPKG_NODE)
        self.assertEqual(node.split_deleted("/usr/bin/node (deleted)"), ("/usr/bin/node", True))
        self.assertEqual(node.split_deleted("/usr/bin/node"), ("/usr/bin/node", False))
        self.assertEqual(node.split_deleted(None), (None, False))


if __name__ == "__main__":
    unittest.main()
