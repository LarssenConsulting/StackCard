import json
import os
import tempfile
import unittest

from discover_agent.collectors import dbrefs

WP_CONFIG = """<?php
define( 'DB_NAME', 'wp_blog' );
define("DB_USER", "blog");
define( 'DB_PASSWORD', 's3cret-wp' );
define( 'DB_HOST', 'localhost' );
define( 'AUTH_KEY', 'k3y-wp' );
$table_prefix = 'wp_';
"""

LARAVEL_ENV = """APP_NAME="Shop"
APP_KEY=base64:k3y-laravel=
# database
DB_CONNECTION=mysql
export DB_HOST = 127.0.0.1
DB_PORT=3306
DB_DATABASE="shop_prod"
DB_USERNAME='shop'   # app user
DB_PASSWORD=s3cret-laravel
DATABASE_URL=
"""

NODE_ENV = """NODE_ENV=production
DATABASE_URL="mysql://api:p%40ss-s3cret@db.lan:3306/api_prod?serverVersion=8.0"
JWT_SECRET=s3cret-jwt
"""


class ParsersTest(unittest.TestCase):
    def test_wp_config(self):
        self.assertEqual(
            dbrefs.parse_wp_config(WP_CONFIG),
            {"db_name": "wp_blog", "db_user": "blog", "db_host": "localhost"},
        )

    def test_wp_config_without_db_name(self):
        self.assertIsNone(dbrefs.parse_wp_config("<?php echo 1;"))

    def test_env_handles_export_quotes_comments_and_drops_secrets(self):
        env = dbrefs.parse_env(LARAVEL_ENV)
        self.assertEqual(env["DB_HOST"], "127.0.0.1")
        self.assertEqual(env["DB_DATABASE"], "shop_prod")
        self.assertEqual(env["DB_USERNAME"], "shop")
        self.assertEqual(env["APP_NAME"], "Shop")
        self.assertEqual(env["DATABASE_URL"], "")
        for key in ("DB_PASSWORD", "APP_KEY"):
            self.assertNotIn(key, env)

    def test_refs_from_laravel_env(self):
        self.assertEqual(
            dbrefs.refs_from_env(dbrefs.parse_env(LARAVEL_ENV)),
            [{"db_name": "shop_prod", "db_user": "shop", "db_host": "127.0.0.1"}],
        )

    def test_database_url_drops_password(self):
        self.assertEqual(
            dbrefs.parse_database_url("mysql://api:p%40ss@db.lan:3306/api_prod?serverVersion=8"),
            {"db_name": "api_prod", "db_user": "api", "db_host": "db.lan"},
        )

    def test_non_mysql_url_is_ignored(self):
        self.assertIsNone(dbrefs.parse_database_url("postgres://u:p@h/db"))
        self.assertIsNone(dbrefs.parse_database_url(""))

    def test_non_mysql_connection_is_ignored(self):
        env = dbrefs.parse_env("DB_CONNECTION=pgsql\nDB_DATABASE=x\nDB_USERNAME=y\n")
        self.assertEqual(dbrefs.refs_from_env(env), [])


class FindDbRefsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.root = self.tmp.name

    def tearDown(self):
        self.tmp.cleanup()

    def write(self, rel, text):
        path = os.path.join(self.root, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, "w") as fh:
            fh.write(text)
        return path

    def test_laravel_public_dir_reads_parent_env(self):
        env_path = self.write("shop/.env", LARAVEL_ENV)
        os.makedirs(os.path.join(self.root, "shop/public"))
        refs = dbrefs.find_db_refs(os.path.join(self.root, "shop/public"))
        self.assertEqual(
            refs,
            [{"db_name": "shop_prod", "db_user": "shop", "db_host": "127.0.0.1", "source": env_path}],
        )

    def test_wordpress_in_document_root(self):
        path = self.write("blog/wp-config.php", WP_CONFIG)
        refs = dbrefs.find_db_refs(os.path.join(self.root, "blog"))
        self.assertEqual(refs[0]["source"], path)
        self.assertEqual(refs[0]["db_name"], "wp_blog")

    def test_stops_at_first_level_with_a_config_file(self):
        self.write("app/.env", "DB_DATABASE=outer\nDB_USERNAME=o\n")
        self.write("app/site/.env.production", NODE_ENV)
        refs = dbrefs.find_db_refs(os.path.join(self.root, "app/site"))
        self.assertEqual([r["db_name"] for r in refs], ["api_prod"])

    def test_duplicates_across_env_files_are_merged(self):
        self.write("x/.env", "DB_DATABASE=d\nDB_USERNAME=u\nDB_HOST=localhost\n")
        self.write("x/.env.local", "DB_DATABASE=d\nDB_USERNAME=u\nDB_HOST=localhost\n")
        self.assertEqual(len(dbrefs.find_db_refs(os.path.join(self.root, "x"))), 1)

    def test_no_secret_ever_leaves_the_parser(self):
        self.write("all/wp-config.php", WP_CONFIG)
        self.write("all/.env", LARAVEL_ENV + NODE_ENV)
        dumped = json.dumps(dbrefs.find_db_refs(os.path.join(self.root, "all")))
        for secret in ("s3cret", "k3y", "p%40ss", "p@ss"):
            self.assertNotIn(secret, dumped)

    def test_missing_dir_returns_empty(self):
        self.assertEqual(dbrefs.find_db_refs(os.path.join(self.root, "nope")), [])
        self.assertEqual(dbrefs.find_db_refs(None), [])


if __name__ == "__main__":
    unittest.main()
