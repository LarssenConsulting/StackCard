import json
import unittest

from discover_agent import util
from discover_agent.sections import apache as section

CTL_M = """\
Loaded Modules:
 core_module (static)
 so_module (static)
 rewrite_module (shared)
 php_module (shared)
 alias_module (shared)
"""

CTL_S = """\
VirtualHost configuration:
*:80                   is a NameVirtualHost
         default server a.example (/etc/apache2/sites-enabled/a.conf:1)
         port 80 namevhost a.example (/etc/apache2/sites-enabled/a.conf:1)
*:443                  b.example (/etc/apache2/sites-enabled/b.conf:2)
"""

A_CONF = """\
<VirtualHost *:80>
    ServerName a.example
    ServerAdmin root@a.example
</VirtualHost>
"""

B_CONF = """\
# comment
<VirtualHost *:443>
    ServerName b.example
    SSLCertificateKeyFile /etc/ssl/private/b.key
</VirtualHost>
<VirtualHost *:443>
    ServerName b.example
    SSLCertificateKeyFile /etc/ssl/private/b.key
</VirtualHost>
"""

APACHE2_CONF = """\
ServerAdmin root@x
protocols h2 http/1.1
# Protocols h2c
<Directory /var/www/>
\tallowoverride  All
</Directory>
LimitRequestFieldSize 16000
"""

X_CONF = """\
<VirtualHost *:80>
    VirtualDocumentRoot /var/www/%0/public
</VirtualHost>
"""


def make(files=None, outputs=None, walk=None, links=None, targets=None, walk_errors=()):
    files = {} if files is None else files
    outputs = {"apache2ctl -M": CTL_M, "apache2ctl -S": CTL_S} if outputs is None else outputs

    def runner(cmd, **kw):
        out = outputs.get(" ".join(cmd))
        if out is None:
            raise util.CommandError(f"command not found: {cmd[0]}")
        if isinstance(out, Exception):
            raise out
        return out

    walk = sorted(files) if walk is None else walk

    def walker(root, onerror=None):
        for exc in walk_errors:
            onerror(exc)
        return [p for p in walk if p.startswith(root + "/")]

    return section.collect(
        runner=runner,
        reader=files.get,
        walker=walker,
        read_target=(targets or {}).get,
        islink=lambda p: p in (links or {}),
        resolve=lambda p: (links or {}).get(p, p),
    )


FILES = {
    "/etc/apache2/apache2.conf": APACHE2_CONF,
    "/etc/apache2/sites-available/a.conf": A_CONF,
    "/etc/apache2/sites-available/b.conf": B_CONF,
    "/etc/apache2/sites-available/x.conf": X_CONF,
}


class ModulesTest(unittest.TestCase):
    def test_modules_from_ctl_m(self):
        data = make(FILES).data
        self.assertEqual(data["modules"], ["alias_module", "core_module", "php_module", "rewrite_module", "so_module"])


class VhostsTest(unittest.TestCase):
    def test_vhosts_from_ctl_s(self):
        files = {"/etc/apache2/sites-enabled/a.conf": A_CONF, "/etc/apache2/sites-enabled/b.conf": B_CONF}
        files.update(FILES)
        data = make(files).data
        self.assertEqual(
            [(v["server_name"], v["port"], v["file"], v["line"]) for v in data["vhosts"]],
            [("a.example", 80, "/etc/apache2/sites-enabled/a.conf", 1),
             ("b.example", 443, "/etc/apache2/sites-enabled/b.conf", 2)],
        )


class DirectivesTest(unittest.TestCase):
    def test_extract_directives(self):
        data = make(FILES).data
        found = [(d["file"], d["line"], d["directive"], d["value"]) for d in data["directives"]]
        self.assertIn(("/etc/apache2/apache2.conf", 2, "Protocols", "h2 http/1.1"), found)
        self.assertIn(("/etc/apache2/apache2.conf", 5, "AllowOverride", "All"), found)
        self.assertIn(("/etc/apache2/apache2.conf", 7, "LimitRequestFieldSize", "16000"), found)
        self.assertIn(("/etc/apache2/sites-available/x.conf", 2, "VirtualDocumentRoot", "/var/www/%0/public"), found)
        self.assertEqual(len(found), 4)  # the commented Protocols line is ignored
        dumped = json.dumps(data["directives"])
        self.assertNotIn("ServerAdmin", dumped)
        self.assertNotIn("SSLCertificateKeyFile", dumped)
        self.assertNotIn("b.key", dumped)

    def test_enabled_flag(self):
        links = {"/etc/apache2/sites-enabled/x.conf": "/etc/apache2/sites-available/x.conf"}
        files = dict(FILES)
        files["/etc/apache2/sites-available/y.conf"] = "Protocols h2\n"
        files["/etc/apache2/sites-enabled/x.conf"] = X_CONF  # what the link shows
        data = make(files, links=links).data
        by_file = {d["file"]: d["enabled"] for d in data["directives"]}
        self.assertTrue(by_file["/etc/apache2/apache2.conf"])
        self.assertTrue(by_file["/etc/apache2/sites-available/x.conf"])
        self.assertFalse(by_file["/etc/apache2/sites-available/y.conf"])
        self.assertNotIn("/etc/apache2/sites-enabled/x.conf", by_file)  # not counted twice

    def test_unreadable_file_is_a_source_error(self):
        files = dict(FILES)
        walk = sorted(files) + ["/etc/apache2/conf-available/secret.conf"]
        result = make(files, walk=walk)
        self.assertIn("/etc/apache2/conf-available/secret.conf", " ".join(result.source_errors))
        self.assertEqual(len(result.data["directives"]), 4)

    def test_value_is_redacted(self):
        files = {"/etc/apache2/apache2.conf": "Protocols https://u:pw@h.example/x\n"}
        value = make(files).data["directives"][0]["value"]
        self.assertNotIn("pw@", value)


class LinkTest(unittest.TestCase):
    LINK = "/etc/apache2/sites-enabled/x.conf"

    def test_link_outside_root_is_read_directly(self):
        files = {self.LINK: "unused"}
        result = make(files, links={self.LINK: "/srv/app/x.conf"}, targets={"/srv/app/x.conf": "Protocols h2\n"},
                      outputs={"apache2ctl -M": CTL_M, "apache2ctl -S": ""})
        self.assertEqual(
            [(d["file"], d["line"], d["enabled"]) for d in result.data["directives"]],
            [("/srv/app/x.conf", 1, True)],
        )
        self.assertEqual(result.source_errors, {})

    def test_dangling_link_is_a_source_error(self):
        result = make({self.LINK: "unused"}, links={self.LINK: "/srv/gone.conf"}, targets={})
        self.assertEqual(result.data["directives"], [])
        self.assertIn("read /srv/gone.conf", result.source_errors)

    def test_relative_link_resolved_to_available(self):
        # realpath already turned ../sites-available/x.conf into an absolute path
        files = {self.LINK: "unused", "/etc/apache2/sites-available/x.conf": X_CONF}
        result = make(files, links={self.LINK: "/etc/apache2/sites-available/x.conf"})
        self.assertEqual([(d["file"], d["enabled"]) for d in result.data["directives"]],
                         [("/etc/apache2/sites-available/x.conf", True)])

    def test_walk_error_is_a_source_error(self):
        result = make(FILES, walk_errors=[PermissionError(13, "denied", "/etc/apache2/private")])
        self.assertIn("walk /etc/apache2/private", result.source_errors)


class ParsingTest(unittest.TestCase):
    def test_backslash_continued_directive(self):
        files = {"/etc/apache2/apache2.conf": "Protocols \\\n  h2 \\\n  http/1.1\nAllowOverride None\n"}
        found = [(d["line"], d["directive"], d["value"]) for d in make(files).data["directives"]]
        self.assertEqual(found, [(1, "Protocols", "h2  http/1.1"), (4, "AllowOverride", "None")])

    def test_ipv6_virtualhost_port(self):
        conf = "<VirtualHost [2001:db8::1]:8080>\n  ServerName v6.example\n</VirtualHost>\n"
        ctl_s = "[2001:db8::1]:8080 v6.example (/etc/apache2/sites-enabled/v6.conf:1)\n"
        files = {"/etc/apache2/sites-enabled/v6.conf": conf}
        vhosts = make(files, outputs={"apache2ctl -M": CTL_M, "apache2ctl -S": ctl_s}).data["vhosts"]
        self.assertEqual([(v["server_name"], v["port"]) for v in vhosts], [("v6.example", 8080)])

    def test_vhost_without_block_falls_back_to_ctl_s(self):
        ctl_s = "         port 80 namevhost ghost.example (/etc/apache2/sites-enabled/g.conf:7)\n"
        files = {"/etc/apache2/sites-enabled/g.conf": "# generated\n"}
        vhosts = make(files, outputs={"apache2ctl -M": CTL_M, "apache2ctl -S": ctl_s}).data["vhosts"]
        self.assertEqual(vhosts, [{"server_name": "ghost.example", "port": 80,
                                   "file": "/etc/apache2/sites-enabled/g.conf", "line": 7}])

    def test_vhosts_sorted(self):
        ctl_s = ("*:443 z.example (/etc/apache2/sites-enabled/z.conf:1)\n"
                 "*:80 a.example (/etc/apache2/sites-enabled/a.conf:1)\n")
        files = {"/etc/apache2/sites-enabled/z.conf": "<VirtualHost *:443>\nServerName z.example\n</VirtualHost>\n",
                 "/etc/apache2/sites-enabled/a.conf": "<VirtualHost *:80>\nServerName a.example\n</VirtualHost>\n"}
        vhosts = make(files, outputs={"apache2ctl -M": CTL_M, "apache2ctl -S": ctl_s}).data["vhosts"]
        self.assertEqual([v["server_name"] for v in vhosts], ["a.example", "z.example"])


class FailureTest(unittest.TestCase):
    def test_no_apache_is_not_installed(self):
        with self.assertRaises(util.NotInstalled):
            make(FILES, outputs={})

    def test_ctl_s_failure_is_null_with_source_error(self):
        result = make(FILES, outputs={"apache2ctl -M": CTL_M, "apache2ctl -S": util.CommandError("boom")})
        self.assertIsNone(result.data["vhosts"])
        self.assertIn("apache2ctl -S", result.source_errors)
        self.assertIsNotNone(result.data["modules"])

    def test_ctl_m_failure_is_null_with_source_error(self):
        result = make(FILES, outputs={"apache2ctl -M": util.CommandError("boom"), "apache2ctl -S": CTL_S})
        self.assertIsNone(result.data["modules"])
        self.assertIn("apache2ctl -M", result.source_errors)
        self.assertIsNotNone(result.data["vhosts"])


if __name__ == "__main__":
    unittest.main()
