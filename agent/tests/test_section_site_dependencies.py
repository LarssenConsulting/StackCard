import json
import unittest

from discover_agent import util
from discover_agent.sections import site_dependencies as section

LOCK = json.dumps({
    "packages": [
        {"name": "laravel/framework", "version": "v11.9.0",
         "source": {"url": "https://u:p@git/x.git"}, "dist": {"url": "https://u:p@h/x.zip"},
         "notification-url": "https://packagist.org/downloads/"},
    ],
    "packages-dev": [{"name": "phpunit/phpunit", "version": "11.0.1", "source": {"url": "x"}}],
})


class Files:
    def __init__(self, files):
        self.files, self.calls = files, []

    def reader(self, path, limit=1024 * 1024):
        self.calls.append(path)
        return self.files.get(path)

    def exists(self, path):
        return path in self.files or path in self.present


Files.present = ()


def sites_of(*specs):
    return lambda: [{"server_name": n, "port": p, "type": "php-fpm", "app_dir": d} for n, p, d in specs]


def run(files, sites, present=()):
    f = Files(files)
    f.present = set(present)
    return f, section.collect(load_sites=sites, reader=f.reader, exists=f.exists)


class SiteDependenciesTest(unittest.TestCase):
    def test_composer_lock_name_version_only(self):
        _, result = run({"/srv/a/composer.lock": LOCK}, sites_of(("a.example", 443, "/srv/a")))
        entry = result.data[0]
        self.assertEqual(entry["composer"], [
            {"name": "laravel/framework", "version": "v11.9.0", "dev": False},
            {"name": "phpunit/phpunit", "version": "11.0.1", "dev": True},
        ])
        text = json.dumps(result.data)
        for forbidden in ("source", "dist", "notification", "u:p"):
            self.assertNotIn(forbidden, text)

    def test_same_app_dir_read_once(self):
        f, result = run({"/srv/a/composer.lock": LOCK},
                        sites_of(("a.example", 80, "/srv/a"), ("a.example", 443, "/srv/a")))
        self.assertEqual(f.calls.count("/srv/a/composer.lock"), 1)
        self.assertEqual(len(result.data), 1)
        self.assertEqual(result.data[0]["sites"], [
            {"server_name": "a.example", "port": 80}, {"server_name": "a.example", "port": 443}])

    def test_lock_found_in_parent_of_public(self):
        _, result = run({"/srv/a/composer.lock": LOCK}, sites_of(("a.example", 443, "/srv/a/public")))
        self.assertEqual(len(result.data[0]["composer"]), 2)

    def test_oversized_lock_is_null_with_source_error(self):
        _, result = run({}, sites_of(("a.example", 443, "/srv/a")), present=["/srv/a/composer.lock"])
        self.assertIsNone(result.data[0]["composer"])
        self.assertIn("composer.lock /srv/a", result.source_errors)

    def test_malformed_lock_is_null_without_echoing_content(self):
        _, result = run({"/srv/a/composer.lock": "{secret-token-xyz"}, sites_of(("a.example", 443, "/srv/a")))
        self.assertIsNone(result.data[0]["composer"])
        self.assertNotIn("secret-token-xyz", json.dumps(result.source_errors))
        self.assertTrue(result.source_errors)

    def test_no_lock_is_empty_list(self):
        _, result = run({}, sites_of(("a.example", 443, "/srv/a")))
        self.assertEqual(result.data[0]["composer"], [])
        self.assertEqual(result.source_errors, {})

    def test_applications_wordpress_and_phpmyadmin_only(self):
        files = {
            "/srv/a/wp-includes/version.php": "$wp_version = '6.6.2';",
            "/srv/a/composer.json": "{}",
            "/srv/a/package.json": json.dumps({"dependencies": {"express": "4"}}),
            "/srv/a/composer.lock": json.dumps({"packages": [
                {"name": "phpmyadmin/phpmyadmin", "version": "5.2.1"},
                {"name": "laravel/framework", "version": "11.0.0"}]}),
        }
        _, result = run(files, sites_of(("a.example", 443, "/srv/a")))
        self.assertEqual(result.data[0]["applications"], [
            {"name": "WordPress", "version": "6.6.2"}, {"name": "phpMyAdmin", "version": "5.2.1"}])

    def test_non_php_sites_and_missing_app_dir_are_skipped(self):
        sites = lambda: [
            {"server_name": "n", "port": 443, "type": "node", "app_dir": "/srv/n"},
            {"server_name": "p", "port": 443, "type": "proxy", "app_dir": None},
        ]
        _, result = run({}, sites)
        self.assertEqual(result.data, [])

    def test_apache_absent_is_not_installed(self):
        def runner(cmd, **kw):
            raise util.CommandError("command not found: apache2ctl")
        with self.assertRaises(util.NotInstalled):
            section.collect(runner=runner)


class HostileTest(unittest.TestCase):
    def test_credentialed_version_is_dropped(self):
        lock = json.dumps({"packages": [
            {"name": "a/b", "version": "git+https://u:token@host/x.git"},
            {"name": "a/c", "version": "dev-main"}, {"name": "a/d", "version": "v1.2.3"},
            {"name": "a/e", "version": "1.2.3-beta+x"}]})
        _, result = run({"/srv/a/composer.lock": lock}, sites_of(("a.example", 443, "/srv/a")))
        self.assertNotIn("token", json.dumps(result.data))
        versions = {p["name"]: p["version"] for p in result.data[0]["composer"]}
        self.assertEqual(versions, {"a/b": None, "a/c": "dev-main", "a/d": "v1.2.3", "a/e": "1.2.3-beta+x"})

    def test_phpmyadmin_version_is_filtered(self):
        files = {"/srv/a/composer.lock": json.dumps({"packages": [
            {"name": "phpmyadmin/phpmyadmin", "version": "https://u:token@h/x"}]}),
            "/srv/a/libraries/classes/Version.php": "const VERSION = 'x@y:z';"}
        _, result = run(files, sites_of(("a.example", 443, "/srv/a")))
        self.assertNotIn("token", json.dumps(result.data))
        self.assertEqual(result.data[0]["applications"], [])

    def test_hostile_lock_shapes(self):
        shapes = [
            json.dumps({"packages": {"a": 1}, "packages-dev": "x"}),
            json.dumps({"packages": [1, None, {"name": 5, "version": 6}, {"name": "ok/x", "version": ["1"]}]}),
            "[" * 100000,
        ]
        for lock in shapes:
            _, result = run({"/srv/a/composer.lock": lock}, sites_of(("a.example", 443, "/srv/a")))
            self.assertIn(result.data[0]["composer"], (None, [], [{"name": "ok/x", "version": None, "dev": False}]))

    def test_sites_are_sorted(self):
        _, result = run({}, sites_of(("b.example", 443, "/srv/a"), ("a.example", 80, "/srv/a")))
        self.assertEqual([s["server_name"] for s in result.data[0]["sites"]], ["a.example", "b.example"])

    def test_one_failing_vhost_does_not_hide_the_others(self):
        from discover_agent.collectors import apache, sites as sc
        import dataclasses
        good = {"server_name": "g", "port": 80, "type": "static", "app_dir": "/srv/g"}
        calls = []
        def classify(vhost, ctx):
            calls.append(vhost)
            if vhost == "bad":
                raise ValueError("boom")
            return good
        orig = (apache.load_vhosts, sc.build_context, sc.classify)
        apache.load_vhosts = lambda runner: ["bad", "ok"]
        sc.build_context = lambda runner: sc.SiteContext({}, {}, False, None, None, None)
        sc.classify = classify
        try:
            f = Files({}); f.present = set()
            result = section.collect(reader=f.reader, exists=f.exists)
        finally:
            apache.load_vhosts, sc.build_context, sc.classify = orig
        self.assertEqual(len(result.data), 1)
        self.assertEqual(len(result.source_errors), 1)


if __name__ == "__main__":
    unittest.main()
