import json
import unittest

from discover_agent import util
from discover_agent.sections import node_apps as section

SS = 'LISTEN 0 511 127.0.0.1:3000 0.0.0.0:* users:(("node",pid=10,fd=20))\n'


def procs(manager="pm2"):
    return {10: {"user": "front", "group": "front", "cwd": "/home/front/app", "process_manager": manager}}


def run(exe, files=None, manager="pm2", exists=None, runner=None):
    files = files or {}
    calls = []

    def default_runner(cmd, **kw):
        calls.append(cmd[0])
        if cmd[0] not in ("ss", "dpkg-query"):
            raise AssertionError(f"executed {cmd}")
        return SS if cmd[0] == "ss" else "20.18.0-1nodesource1"

    return calls, section.collect(
        runner=runner or default_runner, exe_of={10: exe}.get, proc_info=procs(manager).get,
        read_package=lambda d: ("app", "1.0.0"), detect_technologies=lambda d: [],
        reader=lambda p, limit=1024 * 1024: files.get(p),
        exists=exists or (lambda p: p in files),
    )


V3 = json.dumps({"lockfileVersion": 3, "packages": {
    "": {"name": "app", "version": "1.0.0", "dependencies": {"a": "1"}},
    "node_modules/a": {"version": "1.2.3", "resolved": "https://u:p@r/a.tgz", "integrity": "sha512-xxx"},
    "node_modules/a/node_modules/b": {"version": "2.0.0", "resolved": "https://r/b.tgz", "integrity": "sha512-yyy"},
    "node_modules/@s/c": {"version": "3.0.0"},
    "node_modules/b": {"version": "2.0.0"},
}})
V1 = json.dumps({"lockfileVersion": 1, "dependencies": {
    "a": {"version": "1.2.3", "resolved": "https://r/a.tgz", "integrity": "sha1-x",
          "dependencies": {"b": {"version": "2.0.0", "resolved": "r", "integrity": "i"}}}}})


class NodeAppsTest(unittest.TestCase):
    def test_npm_lock_never_keeps_resolved(self):
        for lock in (V3, V1):
            _, result = run("/usr/bin/node", {"/home/front/app/package-lock.json": lock})
            text = json.dumps(result.data)
            self.assertNotIn("resolved", text)
            self.assertNotIn("integrity", text)
            self.assertNotIn("u:p", text)
            deps = result.data["apps"][0]["dependencies"]
            self.assertIn({"name": "a", "version": "1.2.3"}, deps)
            self.assertEqual(deps, sorted(deps, key=lambda d: (d["name"], d["version"] or "")))
            self.assertNotIn({"name": "app", "version": "1.0.0"}, deps)
            self.assertEqual(len(deps), len({(d["name"], d["version"]) for d in deps}))
        _, result = run("/usr/bin/node", {"/home/front/app/package-lock.json": V3})
        self.assertIn({"name": "@s/c", "version": "3.0.0"}, result.data["apps"][0]["dependencies"])

    def test_node_binary_is_never_executed(self):
        calls, result = run("/home/x/evil/node")
        self.assertIsNone(result.data["apps"][0]["node_version"])
        self.assertEqual(result.data["apps"][0]["exe"], "/home/x/evil/node")
        self.assertTrue(set(calls) <= {"ss", "dpkg-query"})

    def test_system_node_version_from_dpkg(self):
        _, result = run("/usr/bin/node")
        self.assertEqual(result.data["apps"][0]["node_version"], "20.18.0")

    def test_node_manager_and_cwd(self):
        _, result = run("/home/front/.nvm/versions/node/v22.11.0/bin/node", manager="systemd:app.service")
        app = result.data["apps"][0]
        self.assertEqual(app["exe"], "/home/front/.nvm/versions/node/v22.11.0/bin/node")
        self.assertEqual(app["node_version"], "22.11.0")
        self.assertEqual(app["process_manager"], "systemd:app.service")
        self.assertEqual(app["app_dir"], "/home/front/app")
        self.assertNotIn("pid", json.dumps(result.data))

    def test_no_lock_is_empty_list(self):
        _, result = run("/usr/bin/node")
        self.assertEqual(result.data["apps"][0]["dependencies"], [])
        self.assertEqual(result.source_errors, {})

    def test_malformed_and_oversized_locks_are_null(self):
        path = "/home/front/app/package-lock.json"
        _, result = run("/usr/bin/node", {path: "{not-json-SECRET"})
        self.assertIsNone(result.data["apps"][0]["dependencies"])
        self.assertNotIn("SECRET", json.dumps(result.source_errors))
        _, result = run("/usr/bin/node", exists=lambda p: p == path)
        self.assertIsNone(result.data["apps"][0]["dependencies"])
        self.assertTrue(result.source_errors)

    def test_ss_missing_is_not_installed(self):
        def runner(cmd, **kw):
            raise util.CommandError("command not found: ss")
        with self.assertRaises(util.NotInstalled):
            run("/usr/bin/node", runner=runner)


class StaleAndSystemTest(unittest.TestCase):
    def test_deleted_system_binary_stays_in_the_inventory(self):
        _, result = run("/usr/bin/node (deleted)")
        app = result.data["apps"][0]
        self.assertEqual(app["exe"], "/usr/bin/node")
        self.assertTrue(app["exe_deleted"])
        self.assertIsNone(app["node_version"])

    def test_deleted_binary_keeps_a_version_the_path_gives(self):
        _, result = run("/home/f/.nvm/versions/node/v22.11.0/bin/node (deleted)")
        app = result.data["apps"][0]
        self.assertTrue(app["exe_deleted"])
        self.assertEqual(app["node_version"], "22.11.0")

    def test_live_binary_is_not_deleted(self):
        _, result = run("/usr/bin/node")
        self.assertFalse(result.data["apps"][0]["exe_deleted"])

    def test_usr_local_bin_node_has_no_dpkg_version(self):
        calls, result = run("/usr/local/bin/node")
        self.assertIsNone(result.data["apps"][0]["node_version"])
        self.assertNotIn("dpkg-query", calls)

    def test_usr_bin_nodejs_uses_dpkg(self):
        _, result = run("/usr/bin/nodejs")
        self.assertEqual(result.data["apps"][0]["node_version"], "20.18.0")

    def test_listen_is_sorted(self):
        ss = ('LISTEN 0 1 127.0.0.1:4000 0.0.0.0:* users:(("node",pid=10,fd=1))\n'
              'LISTEN 0 1 0.0.0.0:3000 0.0.0.0:* users:(("node",pid=10,fd=2))\n')
        def runner(cmd, **kw):
            return ss if cmd[0] == "ss" else "20.18.0"
        _, result = run("/usr/bin/node", runner=runner)
        self.assertEqual([l["port"] for l in result.data["apps"][0]["listen"]], [3000, 4000])

    def test_one_failing_app_does_not_hide_the_others(self):
        ss = ('LISTEN 0 1 127.0.0.1:3000 0.0.0.0:* users:(("node",pid=10,fd=1))\n'
              'LISTEN 0 1 127.0.0.1:4000 0.0.0.0:* users:(("node",pid=11,fd=2))\n')
        procs_ = {10: {"user": "a", "group": "a", "cwd": "/a", "process_manager": None},
                  11: {"user": "b", "group": "b", "cwd": "/b", "process_manager": None}}
        def read_package(d):
            if d == "/a":
                raise ValueError("boom")
            return ("b", "1")
        result = section.collect(
            runner=lambda cmd, **kw: ss if cmd[0] == "ss" else "20.18.0", exe_of=lambda pid: "/usr/bin/node",
            proc_info=procs_.get, read_package=read_package, detect_technologies=lambda d: [],
            reader=lambda p, limit=0: None, exists=lambda p: False)
        self.assertEqual([a["app_dir"] for a in result.data["apps"]], ["/b"])
        self.assertEqual(len(result.source_errors), 1)

    def test_hostile_lock_shapes(self):
        path = "/home/front/app/package-lock.json"
        shapes = [
            json.dumps({"packages": [1, 2]}),
            json.dumps({"packages": {"node_modules/a": {"version": 5}, "node_modules/b": 7, "node_modules/": {}}}),
            json.dumps({"dependencies": {"a": {"version": ["x"]}, "b": "str", "c": {"version": "https://u:token@h/x"}}}),
            "[" * 100000,
            json.dumps({"dependencies": None}),
        ]
        for lock in shapes:
            _, result = run("/usr/bin/node", {path: lock})
            self.assertNotIn("token", json.dumps(result.data))
        # Python 3.10's json module stops near 1000 levels: stay under it for the text round trip...
        deep = {"version": "1.0.0"}
        for _ in range(200):
            deep = {"version": "1.0.0", "dependencies": {"x": deep}}
        _, result = run("/usr/bin/node", {path: json.dumps({"dependencies": {"x": deep}})})
        self.assertEqual(result.data["apps"][0]["dependencies"], [{"name": "x", "version": "1.0.0"}])
        # ...and walk far past the recursion limit directly.
        for _ in range(5000):
            deep = {"version": "1.0.0", "dependencies": {"x": deep}}
        self.assertEqual(
            section.dependencies({"dependencies": {"x": deep}}), [{"name": "x", "version": "1.0.0"}]
        )


if __name__ == "__main__":
    unittest.main()
