import unittest

from discover_agent import util
from discover_agent.sections import packages

DPKG = (
    "zlib1g\t1:1.3.dfsg-3.1ubuntu2\tamd64\tzlib\t1:1.3.dfsg-3.1ubuntu2\tii \n"
    "php8.3-fpm\t8.3.6-0ubuntu0.24.04.13\tamd64\tphp8.3\t8.3.6-0ubuntu0.24.04.13\tii \n"
    "oldpkg\t1.0\tall\toldpkg\t1.0\trc \n"
    "held\t2.0\tamd64\t\t\thi \n"
    "libc6\t2.39-0ubuntu8\ti386\tglibc\t2.39-0ubuntu8\tii \n"
    "libc6\t2.39-0ubuntu8\tamd64\tglibc\t2.39-0ubuntu8\tii \n"
    "half\t1.0\tamd64\thalf\t1.0\tiU \n"
    "\n"
    "garbage line\n"
)


class ParseTest(unittest.TestCase):
    def test_keeps_only_ii_and_hi_sorted_by_package_and_arch(self):
        rows = packages.parse_dpkg(DPKG)
        self.assertEqual(
            [(r["package"], r["arch"], r["status"]) for r in rows],
            [("held", "amd64", "hi"), ("libc6", "amd64", "ii"), ("libc6", "i386", "ii"),
             ("php8.3-fpm", "amd64", "ii"), ("zlib1g", "amd64", "ii")],
        )

    def test_fields(self):
        row = [r for r in packages.parse_dpkg(DPKG) if r["package"] == "php8.3-fpm"][0]
        self.assertEqual(row, {
            "package": "php8.3-fpm", "version": "8.3.6-0ubuntu0.24.04.13", "arch": "amd64",
            "source_package": "php8.3", "source_version": "8.3.6-0ubuntu0.24.04.13", "status": "ii",
        })

    def test_missing_source_falls_back_to_package(self):
        row = [r for r in packages.parse_dpkg(DPKG) if r["package"] == "held"][0]
        self.assertEqual((row["source_package"], row["source_version"]), ("held", "2.0"))


class CollectTest(unittest.TestCase):
    def test_installed_at_tries_arch_then_plain_list(self):
        seen = []
        times = {
            "/var/lib/dpkg/info/libc6:amd64.list": 1700000000.0,
            "/var/lib/dpkg/info/zlib1g.list": 1700086400.0,
        }

        def mtime(path):
            seen.append(path)
            return times.get(path)

        result = packages.collect(runner=lambda cmd, **kw: DPKG, mtime=mtime)
        by = {(r["package"], r["arch"]): r["installed_at"] for r in result.data}
        self.assertEqual(by[("libc6", "amd64")], "2023-11-14T22:13:20Z")
        self.assertEqual(by[("zlib1g", "amd64")], "2023-11-15T22:13:20Z")
        self.assertIsNone(by[("php8.3-fpm", "amd64")])
        self.assertLess(
            seen.index("/var/lib/dpkg/info/zlib1g:amd64.list"), seen.index("/var/lib/dpkg/info/zlib1g.list")
        )
        self.assertEqual(result.source_errors, {})

    def test_command(self):
        calls = []

        def runner(cmd, **kw):
            calls.append(cmd)
            return ""

        result = packages.collect(runner=runner, mtime=lambda p: None)
        self.assertEqual(calls[0], [
            "dpkg-query", "-W",
            "--showformat=${Package}\\t${Version}\\t${Architecture}\\t${source:Package}\\t${source:Version}\\t${db:Status-Abbrev}\\n",
        ])
        self.assertEqual(result.data, [])

    def test_dpkg_query_absent_is_not_installed(self):
        def runner(cmd, **kw):
            raise util.CommandError("command not found: dpkg-query")

        with self.assertRaises(util.NotInstalled):
            packages.collect(runner=runner)
