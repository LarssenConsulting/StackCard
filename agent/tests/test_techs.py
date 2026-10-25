import json
import unittest

from discover_agent.collectors import techs


def reader_for(files):
    calls = []

    def read(path, limit=None):
        calls.append((path, limit))
        return files.get(path)

    read.calls = calls
    return read


COMPOSER_LOCK = json.dumps({"packages": [
    {"name": "laravel/framework", "version": "v12.3.0"},
    {"name": "filament/filament", "version": "v4.1.2"},
    {"name": "monolog/monolog", "version": "3.7.0"},
]})


class DetectTest(unittest.TestCase):
    def test_wordpress_version_from_version_php(self):
        read = reader_for({"/srv/blog/wp-includes/version.php": "<?php\n$wp_version = '6.6.2';\n"})
        self.assertEqual(techs.detect_technologies("/srv/blog", read), [{"name": "WordPress", "version": "6.6.2"}])

    def test_laravel_served_from_public_is_found_in_the_parent(self):
        read = reader_for({"/srv/app/composer.json": "{}", "/srv/app/composer.lock": COMPOSER_LOCK})
        self.assertEqual(
            techs.detect_technologies("/srv/app/public", read),
            [{"name": "Laravel", "version": "12.3.0"}, {"name": "Filament", "version": "4.1.2"}],
        )
        self.assertIn(("/srv/app/composer.lock", techs.LOCK_LIMIT), read.calls)

    def test_without_lock_file_only_names_are_reported(self):
        read = reader_for({"/srv/app/composer.json": json.dumps({"require": {"symfony/framework-bundle": "^7.1"}})})
        self.assertEqual(techs.detect_technologies("/srv/app", read), [{"name": "Symfony", "version": None}])

    def test_node_framework_version_comes_from_the_installed_package(self):
        read = reader_for({
            "/srv/web/package.json": json.dumps({"dependencies": {"next": "^15.0.0", "react": "19.0.0"},
                                                  "devDependencies": {"express": "4"}}),
            "/srv/web/node_modules/next/package.json": json.dumps({"version": "15.0.3"}),
        })
        self.assertEqual(techs.detect_technologies("/srv/web", read), [{"name": "Next.js", "version": "15.0.3"}])

    def test_joomla_version_is_rebuilt_from_its_constants(self):
        read = reader_for({"/srv/j/libraries/src/Version.php": (
            "namespace Joomla\\CMS;\nconst MAJOR_VERSION = 5;\nconst MINOR_VERSION = 1;\nconst PATCH_VERSION = 4;\n")})
        self.assertEqual(techs.detect_technologies("/srv/j", read), [{"name": "Joomla", "version": "5.1.4"}])

    def test_odd_or_hostile_values_are_dropped(self):
        read = reader_for({
            "/srv/x/composer.lock": "not json",
            "/srv/x/composer.json": json.dumps({"require": ["laravel/framework"]}),
            "/srv/x/package.json": json.dumps({"dependencies": {"express": "4"}}),
            "/srv/x/node_modules/express/package.json": json.dumps({"version": "4.0.0; rm -rf /"}),
        })
        self.assertEqual(techs.detect_technologies("/srv/x", read), [{"name": "Express", "version": None}])

    def test_no_marker_anywhere_gives_nothing(self):
        self.assertEqual(techs.detect_technologies("/srv/static/html", reader_for({})), [])
        self.assertEqual(techs.detect_technologies(None, reader_for({})), [])


class ReadPackageTest(unittest.TestCase):
    def test_name_and_version(self):
        read = reader_for({"/srv/api/package.json": json.dumps({"name": "@acme/api", "version": "1.4.0"})})
        self.assertEqual(techs.read_package("/srv/api", read), ("@acme/api", "1.4.0"))

    def test_missing_or_invalid(self):
        self.assertEqual(techs.read_package("/srv/none", reader_for({})), (None, None))
        read = reader_for({"/srv/api/package.json": json.dumps({"name": "<script>", "version": 3})})
        self.assertEqual(techs.read_package("/srv/api", read), (None, None))


if __name__ == "__main__":
    unittest.main()
