import json
import unittest

from discover_agent import util
from discover_agent.sections import apt_sources

LIST = """\
# comment
deb [signed-by=/k arch=amd64] https://u:p@h/ubuntu noble main
deb http://archive.ubuntu.com/ubuntu noble-updates main restricted universe
deb-src http://archive.ubuntu.com/ubuntu noble main
deb [ arch=amd64 signed-by=/usr/share/keyrings/docker.gpg ] https://download.docker.com/linux/ubuntu noble stable
"""

SOURCES = """\
Types: deb
URIs: http://security.ubuntu.com/ubuntu
Suites: noble-security
Components: main universe
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg

Types: deb
URIs: https://off.example.com/apt
Suites: noble
Components: main
Enabled: no

Types: deb deb-src
URIs: https://a.example.com/apt https://b.example.com/apt
Suites: stable
Components: main
"""

PRO = json.dumps({
    "attached": True,
    "account": {"name": "Secret Corp", "id": "acct-1"},
    "contract": {"id": "c-1", "token": "TOK123", "name": "Pro"},
    "services": [
        {"name": "esm-infra", "status": "enabled", "description": "x", "entitled": "yes"},
        {"name": "esm-apps", "status": "disabled", "description": "y"},
    ],
})

POLICY = """\
php8.3-fpm:
  Installed: 8.3.6-0ubuntu0.24.04.13
  Candidate: 8.3.6-0ubuntu0.24.04.13
  Version table:
 *** 8.3.6-0ubuntu0.24.04.13 500
        500 http://archive.ubuntu.com/ubuntu noble-updates/main amd64 Packages
        100 /var/lib/dpkg/status
     8.3.6-0ubuntu0.24.04.1 500
        500 http://security.ubuntu.com/ubuntu noble-security/main amd64 Packages
nodejs:
  Installed: 20.11.0-1nodesource1
  Candidate: 20.11.0-1nodesource1
  Version table:
 *** 20.11.0-1nodesource1 600
        600 https://u:pw@deb.nodesource.com/node_20.x nodistro/main amd64 Packages
        100 /var/lib/dpkg/status
"""

PACKAGES = (
    "php8.3-fpm\t8.3.6\tamd64\tphp8.3\t8.3.6\tii \n"
    "nodejs\t20.11.0\tamd64\tnodejs\t20.11.0\tii \n"
    "zlib1g\t1\tamd64\tzlib\t1\tii \n"
    "linux-image-6.8.0-147-generic\t6.8.0-147\tamd64\tlinux\t6.8.0-147\tii \n"
)


def make_collect(**overrides):
    files = {
        "/etc/apt/sources.list": LIST,
        "/etc/apt/sources.list.d/ubuntu.sources": SOURCES,
    }
    calls = []

    def runner(cmd, **kw):
        calls.append(cmd)
        if cmd[0] == "dpkg-query":
            return PACKAGES
        if cmd[0] == "apt-cache":
            return POLICY
        if cmd[0] == "pro":
            return PRO
        raise AssertionError(cmd)

    values = dict(
        runner=runner,
        reader=files.get,
        listdir=lambda d: ["ubuntu.sources", "notes.txt"] if d == "/etc/apt/sources.list.d" else None,
    )
    values.update(overrides)
    return apt_sources.collect(**values), calls


class SourcesTest(unittest.TestCase):
    def test_one_line_format(self):
        result, _ = make_collect()
        srcs = result.data["sources"]
        mine = [s for s in srcs if s["file"] == "/etc/apt/sources.list"]
        self.assertIn({"file": "/etc/apt/sources.list", "uri": "https://u:***@h/ubuntu",
                       "suite": "noble", "components": ["main"]}, mine)
        self.assertIn("https://download.docker.com/linux/ubuntu", [s["uri"] for s in mine])
        self.assertEqual(len(mine), 3)  # deb-src and comments are not repositories in use

    def test_deb822_multi_blocks_and_enabled(self):
        result, _ = make_collect()
        srcs = [s for s in result.data["sources"] if s["file"].endswith(".sources")]
        uris = [s["uri"] for s in srcs]
        self.assertIn("http://security.ubuntu.com/ubuntu", uris)
        self.assertNotIn("https://off.example.com/apt", uris)
        self.assertIn("https://a.example.com/apt", uris)
        self.assertIn("https://b.example.com/apt", uris)
        sec = [s for s in srcs if s["suite"] == "noble-security"][0]
        self.assertEqual(sec["components"], ["main", "universe"])

    def test_deb822_folded_fields_keep_every_value(self):
        text = (
            "Types: deb\nURIs: http://a.example.com/apt\n http://b.example.com/apt\n"
            "Suites: noble\n noble-updates\nComponents: main\n universe\n restricted\n"
        )
        result, _ = make_collect(
            reader={"/etc/apt/sources.list": "", "/etc/apt/sources.list.d/ubuntu.sources": text}.get
        )
        srcs = result.data["sources"]
        self.assertEqual(len(srcs), 4)
        self.assertEqual({s["uri"] for s in srcs}, {"http://a.example.com/apt", "http://b.example.com/apt"})
        self.assertEqual({s["suite"] for s in srcs}, {"noble", "noble-updates"})
        self.assertEqual(srcs[0]["components"], ["main", "restricted", "universe"])

    def test_no_signed_by_and_no_password(self):
        result, _ = make_collect()
        text = json.dumps(result.data)
        self.assertNotIn("Signed-By", text)
        self.assertNotIn("signed-by", text.lower())
        self.assertNotIn("p@h", text)
        self.assertNotIn(":pw@", text)

    def test_sources_sorted(self):
        result, _ = make_collect()
        srcs = result.data["sources"]
        keys = [(s["file"], s["uri"], s["suite"]) for s in srcs]
        self.assertEqual(keys, sorted(keys))


class OriginsTest(unittest.TestCase):
    def test_single_policy_call_for_filtered_packages(self):
        result, calls = make_collect()
        policy = [c for c in calls if c[0] == "apt-cache"]
        self.assertEqual(len(policy), 1)
        self.assertEqual(policy[0][:2], ["apt-cache", "policy"])
        self.assertEqual(sorted(policy[0][2:]), ["linux-image-6.8.0-147-generic", "nodejs", "php8.3-fpm"])

    def test_origin_of_installed_version(self):
        result, _ = make_collect()
        by = {o["package"]: o for o in result.data["origins"]}
        self.assertEqual(by["php8.3-fpm"]["version"], "8.3.6-0ubuntu0.24.04.13")
        self.assertEqual(by["php8.3-fpm"]["origins"],
                         [{"uri": "http://archive.ubuntu.com/ubuntu", "suite": "noble-updates/main"}])
        self.assertEqual(by["nodejs"]["origins"][0]["uri"], "https://u:***@deb.nodesource.com/node_20.x")
        self.assertEqual([o["package"] for o in result.data["origins"]], sorted(by))

    def test_no_matching_package_skips_apt_cache(self):
        result, calls = make_collect(
            runner=lambda cmd, **kw: "zlib1g\t1\tamd64\tzlib\t1\tii \n" if cmd[0] == "dpkg-query" else PRO
        )
        self.assertEqual(result.data["origins"], [])

    def test_apt_cache_absent_gives_null_and_error(self):
        def runner(cmd, **kw):
            if cmd[0] == "apt-cache":
                raise util.CommandError("command not found: apt-cache")
            return PACKAGES if cmd[0] == "dpkg-query" else PRO

        result, _ = make_collect(runner=runner)
        self.assertIsNone(result.data["origins"])
        self.assertEqual(result.source_errors["origins"], "not_installed")


class TimeoutTest(unittest.TestCase):
    def test_pro_runs_with_10s_timeout(self):
        seen = {}

        def runner(cmd, **kw):
            seen[cmd[0]] = kw.get("timeout")
            return PACKAGES if cmd[0] == "dpkg-query" else (POLICY if cmd[0] == "apt-cache" else PRO)

        make_collect(runner=runner)
        self.assertEqual(seen["pro"], 10)


class ProTest(unittest.TestCase):
    def test_reduced_to_whitelist(self):
        result, _ = make_collect()
        self.assertEqual(result.data["pro"], {
            "attached": True,
            "services": [{"name": "esm-apps", "status": "disabled"}, {"name": "esm-infra", "status": "enabled"}],
        })
        text = json.dumps(result.data)
        for forbidden in ("Secret Corp", "acct-1", "TOK123", "contract", "account"):
            self.assertNotIn(forbidden, text)

    def test_pro_absent(self):
        def runner(cmd, **kw):
            if cmd[0] == "pro":
                raise util.CommandError("command not found: pro")
            return PACKAGES if cmd[0] == "dpkg-query" else POLICY

        result, _ = make_collect(runner=runner)
        self.assertIsNone(result.data["pro"])
        self.assertEqual(result.source_errors["pro"], "not_installed")

    def test_pro_invalid_json_is_an_error_not_a_crash(self):
        def runner(cmd, **kw):
            if cmd[0] == "pro":
                return "not json"
            return PACKAGES if cmd[0] == "dpkg-query" else POLICY

        result, _ = make_collect(runner=runner)
        self.assertIsNone(result.data["pro"])
        self.assertIn("pro", result.source_errors)


class ProBudgetTest(unittest.TestCase):
    def test_a_pro_timeout_keeps_sources_and_origins(self):
        def runner(cmd, **kw):
            if cmd[0] == "pro":
                raise util.CommandError("timeout after 10s: pro status --format json")
            return PACKAGES if cmd[0] == "dpkg-query" else POLICY

        result, _ = make_collect(runner=runner)
        self.assertIsNone(result.data["pro"])
        self.assertIn("timeout", result.source_errors["pro"])
        self.assertTrue(result.data["sources"])
        self.assertTrue(result.data["origins"])
        self.assertEqual(set(result.source_errors), {"pro"})


class ProServiceStateTest(unittest.TestCase):
    def read(self, services, attached):
        def runner(cmd, **kw):
            return json.dumps({"attached": attached, "services": services})

        return apt_sources.read_pro(runner)

    def test_attached_keeps_status(self):
        pro = self.read([{"name": "esm-infra", "status": "enabled", "available": "yes"}], True)
        self.assertEqual(pro["services"], [{"name": "esm-infra", "status": "enabled"}])

    def test_unattached_falls_back_on_available(self):
        pro = self.read(
            [{"name": "esm-apps", "available": "yes"}, {"name": "fips", "available": "no"}], False
        )
        self.assertEqual(
            pro["services"], [{"name": "esm-apps", "status": "yes"}, {"name": "fips", "status": "no"}]
        )

    def test_neither_is_dropped(self):
        self.assertEqual(self.read([{"name": "x"}, {"name": "y", "status": 3, "available": None}], False)["services"], [])
