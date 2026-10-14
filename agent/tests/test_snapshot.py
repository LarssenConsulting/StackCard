import json
import unittest

from discover_agent import snapshot, util
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
        )
        self.assertEqual(result["version"], "Apache/2.4.58 (Ubuntu)")
        self.assertEqual(
            result["sites"],
            [{"server_name": "ok.example.com", "app_dir": "/srv/ok",
              "db_refs": [{"db_name": "ok_db", "source": "/srv/ok"}]}],
        )
        self.assertEqual(errors, [{"collector": "apache", "message": "bad.example.com: ValueError: weird vhost"}])


if __name__ == "__main__":
    unittest.main()
