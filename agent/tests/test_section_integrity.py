import json
import unittest

from discover_agent import util
from discover_agent.sections import REGISTRY, core, integrity

OWNERS = {
    "/usr/bin/sudo": "sudo",
    "/bin/login": "login",
    "/lib/x86_64-linux-gnu/security/pam_unix.so": "libpam-runtime",
    "/usr/bin/mount": "util-linux",
    "/usr/sbin/sshd": "openssh-server",
    "/usr/bin/other": "coreutils",
}
MISSING_LINE = "debsums: missing file /lib/modules/6.8.0/kernel/x.ko (from linux-modules-6.8.0 package)\n"


def owners_of(owners=OWNERS):
    def runner(cmd, **kwargs):
        assert cmd[0] == "dpkg-query", cmd
        runner.calls.append((cmd, kwargs))
        return "".join(f"{owners[p]}: {p}\n" for p in cmd[3:] if p in owners)

    runner.calls = []
    return runner


def debsums(rc=0, out="", err="", exc=None):
    def run_full(cmd, **kwargs):
        run_full.calls.append((cmd, kwargs))
        if exc:
            raise exc
        return rc, out, err

    run_full.calls = []
    return run_full


def collect(rc=0, out="", err="", exc=None, runner=None, modules=True):
    return integrity.collect(
        runner=runner or owners_of(), run_full=debsums(rc, out, err, exc), modules_visible=lambda: modules
    )


class IntegrityTest(unittest.TestCase):
    def test_registered(self):
        self.assertIs(REGISTRY["integrity"], integrity.collect)

    def test_exit_2_is_a_result(self):
        result = collect(2, "\n".join(OWNERS) + "\n/opt/unowned\n")
        by_path = {row["path"]: row for row in result.data["changed"]}
        for path, package in OWNERS.items():
            self.assertEqual(by_path[path]["package"], package)
            self.assertEqual(by_path[path]["sensitive"], package != "coreutils")
        self.assertEqual(by_path["/opt/unowned"], {"path": "/opt/unowned", "package": None, "sensitive": False})
        self.assertEqual([r["path"] for r in result.data["changed"]], sorted(by_path))

    def test_debsums_call(self):
        run_full = debsums()
        integrity.collect(runner=owners_of(), run_full=run_full, modules_visible=lambda: True)
        cmd, kwargs = run_full.calls[0]
        self.assertEqual(cmd, ["debsums", "-c"])
        self.assertEqual(kwargs["timeout"], 280)
        self.assertEqual(kwargs["env"]["LC_ALL"], "C")

    def test_debsums_absent_is_not_installed(self):
        with self.assertRaises(util.NotInstalled):
            collect(exc=util.CommandError("command not found: debsums"))

    def test_clean_is_empty(self):
        runner = owners_of()
        self.assertEqual(
            collect(runner=runner).data, {"changed": [], "missing": 0, "modules_checked": True}
        )
        self.assertEqual(runner.calls, [])

    def test_shape_is_serialisable(self):
        json.dumps(collect(2, "/usr/bin/sudo\n", MISSING_LINE).data)


class ExitStatusTest(unittest.TestCase):
    def test_results(self):
        for rc, out, err in (
            (0, "", ""),
            (2, "", ""),
            (2, "/usr/bin/sudo\n", ""),
            (1, "/usr/bin/sudo\n", ""),
            (3, "/usr/bin/sudo\n", ""),
            (1, "", MISSING_LINE),
            (3, "", MISSING_LINE),
            (2, "", MISSING_LINE),
        ):
            with self.subTest(rc=rc, out=out, err=err):
                self.assertIsInstance(collect(rc, out, err).data, dict)

    def test_hard_failures_raise(self):
        for rc, out, err in (
            (1, "", ""),
            (3, "", ""),
            (1, "  \n", "cannot read status\n"),
            (4, "", ""),
            (255, "/usr/bin/sudo\n", MISSING_LINE),
            (-9, "/usr/bin/sudo\n", ""),
        ):
            with self.subTest(rc=rc, out=out, err=err), self.assertRaises(util.CollectorError) as ctx:
                collect(rc, out, err)
            self.assertIn(str(rc), str(ctx.exception))

    def test_failure_message_shows_a_masked_stderr(self):
        with self.assertRaises(util.CollectorError) as ctx:
            collect(1, "", "boom https://u:S3cret@h\n")
        self.assertIn("boom", str(ctx.exception))
        self.assertNotIn("S3cret", str(ctx.exception))

    def test_failure_is_the_section_error(self):
        envelope = core._run_section(lambda: collect(1, "", "boom\n"), 5, "2026-11-01T08:00:00Z")
        self.assertIn("boom", envelope["error"])
        self.assertIsNone(envelope["data"])

    def test_timeout_is_the_runner_sentinel(self):
        def section():
            return collect(exc=util.CommandTimeout("timeout after 280s: debsums -c"))

        envelope = core._run_section(section, 5, "2026-11-01T08:00:00Z")
        self.assertEqual(envelope["error"], "timeout")


class ModulesAndMissingTest(unittest.TestCase):
    def test_missing_counts_the_stderr_lines(self):
        err = MISSING_LINE * 3 + "debsums: changed something else\n"
        data = collect(2, "/usr/bin/sudo\n", err, modules=False).data
        self.assertEqual(data["missing"], 3)
        self.assertFalse(data["modules_checked"])
        self.assertEqual([r["path"] for r in data["changed"]], ["/usr/bin/sudo"])

    def test_only_missing_files_is_a_result_with_no_changed_file(self):
        data = collect(2, "", MISSING_LINE, modules=False).data
        self.assertEqual((data["changed"], data["missing"]), ([], 1))

    def test_modules_visible(self):
        def boom(path):
            raise PermissionError(path)

        self.assertTrue(integrity.modules_visible(listdir=lambda path: ["6.8.0-100-generic"]))
        self.assertFalse(integrity.modules_visible(listdir=lambda path: []))
        self.assertFalse(integrity.modules_visible(listdir=boom))


class OwnersTest(unittest.TestCase):
    def test_unknown_owner_query_exit_1_is_accepted(self):
        runner = owners_of()
        collect(2, "/opt/a\n", runner=runner)
        cmd, kwargs = runner.calls[0]
        self.assertEqual(kwargs["ok_codes"], (0, 1))
        self.assertEqual(cmd[:2], ["dpkg-query", "-S"])

    def test_batches_owner_queries(self):
        paths = [f"/opt/f{i}" for i in range(1200)]
        runner = owners_of()
        self.assertEqual(len(collect(2, "\n".join(paths), runner=runner).data["changed"]), 1200)
        self.assertEqual(len(runner.calls), 3)

    def test_owner_query_failure_keeps_the_list(self):
        def runner(cmd, **kwargs):
            raise util.CommandError("dpkg-query exited 2")

        result = collect(2, "/usr/bin/sudo\n", runner=runner)
        self.assertEqual(result.data["changed"], [{"path": "/usr/bin/sudo", "package": None, "sensitive": False}])
        self.assertIn("dpkg-query", result.source_errors)

    def test_diversion_is_not_a_package(self):
        def runner(cmd, **kwargs):
            return "diversion by x from: /bin/foo\ndiversion by x to: /bin/foo.distrib\n"

        self.assertIsNone(collect(2, "/bin/foo\n", runner=runner).data["changed"][0]["package"])

    def test_usr_alias_resolves_both_ways(self):
        for listed, owned in (("/bin/login", "/usr/bin/login"), ("/usr/bin/login", "/bin/login")):
            def runner(cmd, owned=owned, **kwargs):
                return f"login: {owned}\n" if owned in cmd else ""

            row = collect(2, listed + "\n", runner=runner).data["changed"][0]
            self.assertEqual(row, {"path": listed, "package": "login", "sensitive": True})

    def test_multi_package_owner_takes_first(self):
        def runner(cmd, **kwargs):
            return "sudo, sudo-ldap: /usr/bin/sudo\n"

        self.assertEqual(collect(2, "/usr/bin/sudo\n", runner=runner).data["changed"][0]["package"], "sudo")


if __name__ == "__main__":
    unittest.main()
