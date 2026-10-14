import unittest

from discover_agent.collectors import sites

POOLS = {
    "/etc/php/8.3/fpm/pool.d/shop.conf": """
; shop pool
[shop]
user = shop
group = shop
listen = /run/php/php8.3-fpm-shop.sock
""",
    "/etc/php/8.1/fpm/pool.d/www.conf": """
[www]
user = www-data
group = www-data
listen = /run/php/php8.1-fpm-$pool.sock
[blog]
user = blog
group = www-data
listen = 127.0.0.1:9001
""",
}

SS = """LISTEN 0      511          127.0.0.1:3000      0.0.0.0:*    users:(("node",pid=1234,fd=20))
LISTEN 0      4096              [::]:8080         [::]:*    users:(("node",pid=2222,fd=3),("node",pid=2223,fd=3))
LISTEN 0      80                   *:3306            *:*    users:(("mariadbd",pid=900,fd=21))
"""


def vhost(**overrides):
    base = {
        "server_name": "site.example.com", "aliases": [], "port": 443, "ssl": True,
        "cert_file": "/etc/ssl/site.pem", "document_root": None, "proxy_pass": None,
        "fpm_socket": None, "assign_user": None, "partial": False,
        "config_file": "/etc/apache2/sites-enabled/site.conf",
    }
    base.update(overrides)
    return base


def context(**overrides):
    values = dict(
        fpm_pools=sites.parse_fpm_pools(POOLS),
        listeners=sites.parse_ss(SS),
        php_module=False,
        proc_info=lambda pid: {
            1234: {"user": "app-api", "group": "app-api", "cwd": "/srv/api",
                   "process_manager": "systemd:shop-api.service"},
        }.get(pid),
        owner=lambda path: ("owner", "www-data") if path else (None, None),
        cert_expiry=lambda path: "2027-01-01T00:00:00Z",
    )
    values.update(overrides)
    return sites.SiteContext(**values)


class ParsersTest(unittest.TestCase):
    def test_fpm_pools_by_socket_with_php_version_and_pool_variable(self):
        pools = sites.parse_fpm_pools(POOLS)
        self.assertEqual(
            pools["/run/php/php8.3-fpm-shop.sock"],
            {"user": "shop", "group": "shop", "php_version": "8.3"},
        )
        self.assertEqual(pools["/run/php/php8.1-fpm-www.sock"]["user"], "www-data")
        self.assertEqual(pools["127.0.0.1:9001"]["user"], "blog")

    def test_ss_maps_port_to_first_pid(self):
        self.assertEqual(sites.parse_ss(SS), {3000: 1234, 8080: 2222, 3306: 900})

    def test_cert_enddate(self):
        self.assertEqual(
            sites.parse_cert_enddate("notAfter=Jan  1 00:00:00 2027 GMT\n"),
            "2027-01-01T00:00:00Z",
        )


class ClassifyTest(unittest.TestCase):
    def test_php_fpm_site_uses_pool_user(self):
        site = sites.classify(
            vhost(document_root="/var/www/shop/public", fpm_socket="/run/php/php8.3-fpm-shop.sock"),
            context(),
        )
        self.assertEqual(site["type"], "php-fpm")
        self.assertEqual((site["system_user"], site["system_group"]), ("shop", "shop"))
        self.assertEqual(site["php_version"], "8.3")
        self.assertEqual(site["app_dir"], "/var/www/shop/public")
        self.assertEqual((site["owner_user"], site["owner_group"]), ("owner", "www-data"))
        self.assertTrue(site["https"])
        self.assertEqual(site["cert_expires_at"], "2027-01-01T00:00:00Z")

    def test_php_fpm_with_unknown_socket_keeps_type_without_user(self):
        site = sites.classify(vhost(document_root="/x", fpm_socket="/run/php/missing.sock"), context())
        self.assertEqual(site["type"], "php-fpm")
        self.assertIsNone(site["system_user"])

    def test_mod_php_defaults_to_www_data(self):
        site = sites.classify(vhost(document_root="/var/www/legacy"), context(php_module=True))
        self.assertEqual(site["type"], "php-mod")
        self.assertEqual(site["system_user"], "www-data")

    def test_mod_php_with_assign_user_id(self):
        site = sites.classify(
            vhost(document_root="/srv/itk", assign_user=("itk", "itkg")), context(php_module=True)
        )
        self.assertEqual((site["system_user"], site["system_group"]), ("itk", "itkg"))

    def test_node_site_resolves_listening_process(self):
        site = sites.classify(vhost(proxy_pass="http://127.0.0.1:3000/"), context())
        self.assertEqual(site["type"], "node")
        self.assertEqual(site["system_user"], "app-api")
        self.assertEqual(site["app_dir"], "/srv/api")
        self.assertEqual(site["process_manager"], "systemd:shop-api.service")
        self.assertEqual(site["upstream"], "http://127.0.0.1:3000/")

    def test_node_site_without_listener_does_not_crash(self):
        site = sites.classify(vhost(proxy_pass="http://localhost:4000/"), context())
        self.assertEqual(site["type"], "node")
        self.assertIsNone(site["system_user"])
        self.assertIsNone(site["app_dir"])
        self.assertIsNone(site["owner_user"])

    def test_external_proxy(self):
        site = sites.classify(vhost(proxy_pass="http://10.0.0.20:8080/"), context())
        self.assertEqual(site["type"], "proxy")
        self.assertEqual(site["upstream"], "http://10.0.0.20:8080/")
        self.assertIsNone(site["system_user"])

    def test_static_site_uses_document_root_owner(self):
        site = sites.classify(vhost(document_root="/var/www/html", ssl=False, cert_file=None), context())
        self.assertEqual(site["type"], "static")
        self.assertEqual(site["system_user"], "owner")
        self.assertFalse(site["https"])
        self.assertIsNone(site["cert_expires_at"])


class ProcessManagerTest(unittest.TestCase):
    def test_systemd_unit_from_cgroup(self):
        self.assertEqual(
            sites.process_manager_from("0::/system.slice/shop-api.service\n", ["node", "systemd"]),
            "systemd:shop-api.service",
        )

    def test_pm2_ancestor_wins(self):
        self.assertEqual(
            sites.process_manager_from("0::/system.slice/pm2-deploy.service\n", ["node", "PM2 v5.3.1: God", "systemd"]),
            "pm2",
        )

    def test_unknown(self):
        self.assertEqual(sites.process_manager_from("0::/user.slice/session-3.scope\n", ["node", "bash"]), "unknown")


if __name__ == "__main__":
    unittest.main()
