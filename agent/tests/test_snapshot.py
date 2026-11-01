import json
import unittest
import unittest.mock

from discover_agent import sections, snapshot, util
from discover_agent import __version__


def boom():
    raise util.CollectorError("MySQL inaccessible (Access denied)")


def crash():
    raise KeyError("unexpected")


class BuildSnapshotTest(unittest.TestCase):
    def test_shape_and_metadata(self):
        snap = snapshot.build_snapshot({
            "server": lambda: {"hostname": "web01"},
            "apache": lambda: None,
            "mysql": lambda: {"version": "8.0"},
        })
        self.assertEqual(snap["schema_version"], 1)
        self.assertEqual(snap["agent_version"], __version__)
        self.assertRegex(snap["collected_at"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$")
        self.assertEqual(snap["server"], {"hostname": "web01"})
        self.assertIsNone(snap["apache"])
        self.assertEqual(snap["errors"], [])
        json.dumps(snap)

    def test_failing_collector_is_isolated(self):
        snap = snapshot.build_snapshot({
            "server": lambda: {"hostname": "web01"},
            "apache": crash,
            "mysql": boom,
        })
        self.assertEqual(snap["server"], {"hostname": "web01"})
        self.assertIsNone(snap["mysql"])
        self.assertIsNone(snap["apache"])
        self.assertEqual(
            snap["errors"],
            [
                {"collector": "apache", "message": "KeyError: 'unexpected'"},
                {"collector": "mysql", "message": "MySQL inaccessible (Access denied)"},
            ],
        )


    def test_failed_server_collector_still_sends_a_minimal_identity(self):
        import socket

        snap = snapshot.build_snapshot({"server": crash, "apache": lambda: None, "mysql": lambda: None})
        self.assertEqual(snap["server"]["hostname"], socket.gethostname())
        self.assertEqual(snap["server"]["interfaces"], [])
        self.assertEqual(snap["errors"][0]["collector"], "server")


class CollectApacheTest(unittest.TestCase):
    def test_none_when_apache_is_not_installed(self):
        self.assertIsNone(snapshot.collect_apache(errors=[], has_apache=lambda: False))

    def test_sites_get_db_refs_and_a_broken_site_is_reported(self):
        vhosts = [
            {"server_name": "ok.example.com", "app": "/srv/ok"},
            {"server_name": "bad.example.com", "app": "/srv/bad"},
        ]

        def classify(vhost, ctx):
            if vhost["server_name"].startswith("bad"):
                raise ValueError("weird vhost")
            return {"server_name": vhost["server_name"], "app_dir": vhost["app"]}

        errors = []
        result = snapshot.collect_apache(
            errors=errors,
            has_apache=lambda: True,
            runner=lambda cmd, **kw: "Server version: Apache/2.4.58 (Ubuntu)\n",
            load_vhosts=lambda: vhosts,
            build_context=lambda: None,
            classify=classify,
            find_db_refs=lambda app_dir: [{"db_name": "ok_db", "source": app_dir}],
            detect_technologies=lambda app_dir: [{"name": "Laravel", "version": "12.3.0"}],
        )
        self.assertEqual(result["version"], "Apache/2.4.58 (Ubuntu)")
        self.assertEqual(
            result["sites"],
            [{"server_name": "ok.example.com", "app_dir": "/srv/ok",
              "db_refs": [{"db_name": "ok_db", "source": "/srv/ok"}],
              "technologies": [{"name": "Laravel", "version": "12.3.0"}]}],
        )
        self.assertEqual(errors, [{"collector": "apache", "message": "bad.example.com: ValueError: weird vhost"}])

    def test_technologies_are_read_once_per_directory(self):
        vhosts = [{"server_name": "shop", "port": 80}, {"server_name": "shop", "port": 443}]
        calls = []
        result = snapshot.collect_apache(
            errors=[],
            has_apache=lambda: True,
            runner=lambda cmd, **kw: "",
            load_vhosts=lambda: vhosts,
            build_context=lambda: None,
            classify=lambda vhost, ctx: dict(vhost, app_dir="/srv/shop"),
            find_db_refs=lambda app_dir: [],
            detect_technologies=lambda app_dir: calls.append(app_dir) or [],
        )
        self.assertEqual(calls, ["/srv/shop"])
        self.assertEqual([s["technologies"] for s in result["sites"]], [[], []])

    def test_node_is_a_default_collector(self):
        self.assertIn("node", snapshot.default_collectors([]))

    def test_docker_is_a_default_collector(self):
        self.assertIn("docker", snapshot.default_collectors([]))

    def test_ufw_is_a_default_collector(self):
        self.assertIn("ufw", snapshot.default_collectors([]))


class SectionsInSnapshotTest(unittest.TestCase):
    def build(self, runner, **kwargs):
        return snapshot.build_snapshot({"server": lambda: {"hostname": "web01"}}, section_runner=runner, **kwargs)

    def test_sections_are_added_and_default_to_levels_one_and_two(self):
        seen = []
        snap = self.build(lambda due: seen.append(due) or {"packages": {"error": None, "data": []}})
        self.assertEqual(seen, [set()])
        self.assertEqual(snap["sections"], {"packages": {"error": None, "data": []}})

    def test_sections_due_is_passed_to_the_runner(self):
        seen = []
        self.build(lambda due: seen.append(due) or {}, sections_due={"integrity"})
        self.assertEqual(seen, [{"integrity"}])

    def test_real_failures_are_mirrored_in_errors(self):
        env = {"collected_at": "t", "source_errors": {}, "data": None, "sha256": None}
        snap = self.build(lambda due: {
            "packages": {**env, "error": "boom"},
            "apt_sources": {**env, "error": "timeout"},
            "kernel": {**env, "error": "too_large"},
            "restart_needed": {**env, "error": "not_installed"},
            "integrity": {"error": "skipped_not_due"},
            "php": {**env, "error": None, "data": [], "sha256": "h"},
        })
        self.assertEqual(
            snap["errors"],
            [{"collector": "sections.packages", "message": "boom"},
             {"collector": "sections.apt_sources", "message": "timeout"},
             {"collector": "sections.kernel", "message": "too_large"}],
        )

    def test_mirrored_message_is_truncated_and_never_empty(self):
        env = {"collected_at": "t", "source_errors": {}, "data": None, "sha256": None}
        snap = self.build(lambda due: {
            "packages": {**env, "error": "x" * 5000},
            "apt_sources": {**env, "error": ""},
        })
        self.assertEqual(len(snap["errors"][0]["message"]), 4000)
        self.assertTrue(snap["errors"][1]["message"])

    def test_a_crashing_runner_keeps_the_rest_of_the_snapshot(self):
        def broken(due):
            raise RuntimeError("runner down")
        snap = self.build(broken)
        self.assertEqual(snap["server"], {"hostname": "web01"})
        self.assertEqual(snap["sections"], {})
        self.assertEqual(snap["errors"], [{"collector": "sections", "message": "RuntimeError: runner down"}])

    def test_caps_apply_to_an_injected_runner(self):
        big = "x" * 100
        env = {"collected_at": "t", "error": None, "source_errors": {}, "data": big,
               "sha256": sections.canonical_sha256(big)}
        with unittest.mock.patch.object(sections.core, "SECTION_MAX", 10):
            snap = self.build(lambda due: {"packages": env})
        self.assertEqual(snap["sections"]["packages"]["error"], "too_large")
        self.assertEqual(snap["errors"], [{"collector": "sections.packages", "message": "too_large"}])

    def test_default_runner_uses_the_registry(self):
        snap = snapshot.build_snapshot({"server": lambda: {}})
        self.assertIsInstance(snap["sections"], dict)
        json.dumps(snap)

    def test_registry_matches_section_names(self):
        from discover_agent import sections

        self.assertEqual(sorted(sections.REGISTRY), sorted(sections.SECTION_NAMES))
        for name, fn in sections.REGISTRY.items():
            self.assertTrue(callable(fn), name)


class SafeMessagesTest(unittest.TestCase):
    def test_collector_error_is_masked(self):
        def fail():
            raise util.CommandError("curl -u admin:S3cret exited 1: https://u:S3cret@h")

        snap = snapshot.build_snapshot({"mysql": fail}, section_runner=lambda due: {})
        self.assertNotIn("S3cret", json.dumps(snap))
        self.assertIn("admin:***", snap["errors"][0]["message"])

    def test_collector_error_is_cut_at_the_central_limit(self):
        def fail():
            raise util.CollectorError("x" * 9000)

        snap = snapshot.build_snapshot({"mysql": fail}, section_runner=lambda due: {})
        self.assertEqual(len(snap["errors"][0]["message"]), 4000)

    def test_empty_collector_message_is_still_valid(self):
        def fail():
            raise util.CollectorError("")

        snap = snapshot.build_snapshot({"mysql": fail}, section_runner=lambda due: {})
        self.assertTrue(snap["errors"][0]["message"])

    def test_vhost_error_is_cut(self):
        def classify(vhost, context):
            raise util.CommandError("y" * 9000 + " token=S3cret")

        errors = []
        snapshot.collect_apache(
            errors, has_apache=lambda: True, runner=lambda cmd: "Server version: Apache/2.4.58\n",
            load_vhosts=lambda: [{"server_name": "a.test"}], build_context=lambda: None, classify=classify,
        )
        self.assertTrue(errors[0]["message"].startswith("a.test: y"))
        self.assertEqual(len(errors[0]["message"]), 4000)

    def test_vhost_error_is_masked(self):
        def classify(vhost, context):
            raise util.CommandError("failed: token=S3cret")

        errors = []
        snapshot.collect_apache(
            errors, has_apache=lambda: True, runner=lambda cmd: "Server version: Apache/2.4.58\n",
            load_vhosts=lambda: [{"server_name": "a.test"}], build_context=lambda: None, classify=classify,
        )
        self.assertEqual(errors[0]["message"], "a.test: failed: token=***")


class SurrogateTest(unittest.TestCase):
    def test_lone_surrogates_cannot_get_the_inventory_refused(self):
        snap = snapshot.build_snapshot(
            {
                "server": lambda: {"hostname": "web01"},
                "node": lambda: {"apps": [{"app_dir": "/srv/caf\udce9", "tags": ("a\udcff",), "n": 1, "x": None}]},
                "docker": lambda: {"k\udcff": "v"},
            },
            section_runner=lambda due: {
                "packages": {"error": "boom \udcff", "data": None, "source_errors": {"s\udcff": "m\udcff"}},
            },
        )
        text = json.dumps(snap, ensure_ascii=False)
        text.encode("utf-8")
        self.assertEqual(snap["node"]["apps"][0]["app_dir"], "/srv/caf?")
        self.assertEqual(snap["node"]["apps"][0]["tags"], ["a?"])
        self.assertEqual(snap["node"]["apps"][0]["n"], 1)
        self.assertIsNone(snap["node"]["apps"][0]["x"])
        self.assertEqual(snap["docker"], {"k?": "v"})
        self.assertEqual(snap["sections"]["packages"]["source_errors"], {"s?": "m?"})
        self.assertEqual(snap["errors"][0]["message"], "boom ?")

    def test_valid_unicode_is_untouched(self):
        snap = snapshot.build_snapshot(
            {"server": lambda: {"hostname": "café-日本"}}, section_runner=lambda due: {}
        )
        self.assertEqual(snap["server"]["hostname"], "café-日本")


if __name__ == "__main__":
    unittest.main()
