import unittest

from discover_agent.collectors import apache

CTL_S = """VirtualHost configuration:
*:80                   is a NameVirtualHost
         default server shop.example.com (/etc/apache2/sites-enabled/shop.conf:1)
         port 80 namevhost shop.example.com (/etc/apache2/sites-enabled/shop.conf:1)
                 alias www.shop.example.com
         port 80 namevhost api.example.com (/etc/apache2/sites-enabled/api.conf:1)
*:443                  shop.example.com (/etc/apache2/sites-enabled/shop.conf:9)
ServerRoot: "/etc/apache2"
Main DocumentRoot: "/var/www/html"
"""

SHOP_CONF = """<VirtualHost *:80>
    ServerName shop.example.com
    ServerAlias www.shop.example.com
    Redirect permanent / https://shop.example.com/
    ErrorLog ${APACHE_LOG_DIR}/shop-error.log
</VirtualHost>

# HTTPS
<VirtualHost *:443>
    servername shop.example.com
    ServerAlias www.shop.example.com
    ServerAlias shop.example.fr  boutique.example.com
    DocumentRoot "/var/www/shop/public"
    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/shop.example.com/fullchain.pem
    <FilesMatch \\.php$>
        SetHandler "proxy:unix:/run/php/php8.3-fpm-shop.sock|fcgi://localhost"
    </FilesMatch>
    # DocumentRoot /old/path
</VirtualHost>
"""

API_CONF = """<VirtualHost *:80>
    ServerName api.example.com
    ProxyPreserveHost On
    ProxyPass /static !
    ProxyPass / http://127.0.0.1:3000/
    ProxyPassReverse / http://127.0.0.1:3000/
</VirtualHost>
"""

LOCATION_CONF = """<VirtualHost *:80>
    ServerName legacy.example.com
    <Location />
        ProxyPass "http://10.0.0.20:8080/"
    </Location>
</VirtualHost>
"""

MACRO_CONF = """<VirtualHost *:80>
    ServerName ${SITE_NAME}
    DocumentRoot /var/www/${SITE_NAME}
</VirtualHost>
Use VHost blog.example.com
"""

ITK_CONF = """<VirtualHost *:8080 *:8081>
    ServerName itk.example.com
    DocumentRoot /srv/itk
    AssignUserID itkuser itkgroup
</VirtualHost>
"""


class CtlSTest(unittest.TestCase):
    def test_lists_unique_vhost_locations_in_order(self):
        self.assertEqual(
            apache.parse_ctl_s(CTL_S),
            [
                ("/etc/apache2/sites-enabled/shop.conf", 1),
                ("/etc/apache2/sites-enabled/api.conf", 1),
                ("/etc/apache2/sites-enabled/shop.conf", 9),
            ],
        )


class VhostTest(unittest.TestCase):
    def test_same_domain_on_80_and_443_gives_two_blocks(self):
        blocks = apache.parse_vhosts_text(SHOP_CONF)
        self.assertEqual([line for line, _ in blocks], [1, 9])
        self.assertEqual([b["port"] for _, b in blocks], [80, 443])

    def test_https_php_fpm_block(self):
        _, block = apache.parse_vhosts_text(SHOP_CONF)[1]
        self.assertEqual(block["server_name"], "shop.example.com")
        self.assertEqual(
            block["aliases"],
            ["www.shop.example.com", "shop.example.fr", "boutique.example.com"],
        )
        self.assertTrue(block["ssl"])
        self.assertEqual(block["cert_file"], "/etc/letsencrypt/live/shop.example.com/fullchain.pem")
        self.assertEqual(block["document_root"], "/var/www/shop/public")
        self.assertEqual(block["fpm_socket"], "/run/php/php8.3-fpm-shop.sock")
        self.assertIsNone(block["proxy_pass"])
        self.assertFalse(block["partial"])

    def test_log_dir_variable_does_not_mark_partial(self):
        _, block = apache.parse_vhosts_text(SHOP_CONF)[0]
        self.assertFalse(block["partial"])
        self.assertFalse(block["ssl"])
        self.assertIsNone(block["document_root"])

    def test_root_proxy_pass_ignores_exclusions(self):
        _, block = apache.parse_vhosts_text(API_CONF)[0]
        self.assertEqual(block["proxy_pass"], "http://127.0.0.1:3000/")

    def test_single_argument_proxy_pass_inside_root_location(self):
        _, block = apache.parse_vhosts_text(LOCATION_CONF)[0]
        self.assertEqual(block["proxy_pass"], "http://10.0.0.20:8080/")

    def test_unresolved_variable_marks_partial(self):
        _, block = apache.parse_vhosts_text(MACRO_CONF)[0]
        self.assertTrue(block["partial"])

    def test_use_macro_in_file_marks_blocks_partial(self):
        self.assertTrue(apache.file_uses_macro(MACRO_CONF))
        self.assertFalse(apache.file_uses_macro(SHOP_CONF))

    def test_assign_user_and_first_port(self):
        _, block = apache.parse_vhosts_text(ITK_CONF)[0]
        self.assertEqual(block["assign_user"], ("itkuser", "itkgroup"))
        self.assertEqual(block["port"], 8080)

    def test_line_continuation_is_joined(self):
        text = "<VirtualHost *:80>\n    ServerAlias a.example.com \\\n        b.example.com\n</VirtualHost>\n"
        _, block = apache.parse_vhosts_text(text)[0]
        self.assertEqual(block["aliases"], ["a.example.com", "b.example.com"])


class LoadVhostsTest(unittest.TestCase):
    def test_keeps_only_blocks_listed_by_apache2ctl(self):
        files = {
            "/etc/apache2/sites-enabled/shop.conf": SHOP_CONF,
            "/etc/apache2/sites-enabled/api.conf": API_CONF + ITK_CONF,
        }
        vhosts = apache.load_vhosts(
            runner=lambda cmd, **kw: CTL_S, reader=files.get
        )
        self.assertEqual(
            [(v["config_file"], v["port"]) for v in vhosts],
            [
                ("/etc/apache2/sites-enabled/shop.conf", 80),
                ("/etc/apache2/sites-enabled/api.conf", 80),
                ("/etc/apache2/sites-enabled/shop.conf", 443),
            ],
        )

    def test_macro_file_marks_its_blocks_partial(self):
        ctl = "*:80 blog (/etc/apache2/sites-enabled/macro.conf:1)\n"
        vhosts = apache.load_vhosts(
            runner=lambda cmd, **kw: ctl,
            reader={"/etc/apache2/sites-enabled/macro.conf": MACRO_CONF}.get,
        )
        self.assertTrue(vhosts[0]["partial"])

    def test_vhosts_generated_by_macros_are_reported_as_partial(self):
        ctl = (
            "*:80                   is a NameVirtualHost\n"
            "         port 80 namevhost blog.example.com (macro 'VHost' (defined on line 1 of "
            "\"/etc/apache2/conf-enabled/macros.conf\") used on line 3 of \"/etc/apache2/sites-enabled/blog.conf\":1)\n"
            "*:443                  shop.example.com (macro 'SSLHost' (defined on line 9 of "
            "\"/etc/apache2/conf-enabled/macros.conf\") used on line 4 of \"/etc/apache2/sites-enabled/shop.conf\":1)\n"
        )
        vhosts = apache.load_vhosts(runner=lambda cmd, **kw: ctl, reader=lambda path: "")
        self.assertEqual(
            [(v["server_name"], v["port"], v["config_file"], v["partial"]) for v in vhosts],
            [
                ("blog.example.com", 80, "/etc/apache2/sites-enabled/blog.conf", True),
                ("shop.example.com", 443, "/etc/apache2/sites-enabled/shop.conf", True),
            ],
        )

    def test_parse_version(self):
        out = "Server version: Apache/2.4.58 (Ubuntu)\nServer built:   2024-10-02T12:40:51\n"
        self.assertEqual(apache.parse_version(out), "Apache/2.4.58 (Ubuntu)")


if __name__ == "__main__":
    unittest.main()
