import unittest
from datetime import datetime, timezone, timedelta

from discover_agent import util


class RunTest(unittest.TestCase):
    def test_returns_stdout(self):
        self.assertEqual(util.run(["echo", "hi"]), "hi\n")

    def test_missing_command_raises(self):
        with self.assertRaises(util.CommandError):
            util.run(["discover-no-such-command-xyz"])

    def test_non_zero_exit_raises_with_stderr(self):
        with self.assertRaises(util.CommandError) as ctx:
            util.run(["sh", "-c", "echo boom >&2; exit 3"])
        self.assertIn("boom", str(ctx.exception))

    def test_timeout_raises(self):
        with self.assertRaises(util.CommandError):
            util.run(["sleep", "5"], timeout=1)


class RunOptionsTest(unittest.TestCase):
    def test_ok_codes_accepts_exit_2(self):
        out = util.run(["sh", "-c", "echo partial; exit 2"], ok_codes=(0, 2))
        self.assertEqual(out, "partial\n")
        with self.assertRaises(util.CommandError):
            util.run(["sh", "-c", "exit 2"])

    def test_cwd_and_env_are_passed(self):
        out = util.run(
            ["sh", "-c", "pwd; echo $DISCOVER_X"], cwd="/", env={"DISCOVER_X": "v", "PATH": "/bin:/usr/bin"}
        )
        self.assertEqual(out, "/\nv\n")

    def test_run_c_sets_c_locale(self):
        out = util.run_c(["sh", "-c", "echo $LC_ALL $LANGUAGE"])
        self.assertEqual(out, "C C\n")

    def test_run_c_overrides_caller_locale_and_keeps_other_env(self):
        out = util.run_c(
            ["sh", "-c", "echo $LC_ALL $KEEP"],
            env={"LC_ALL": "fr_FR.UTF-8", "KEEP": "k", "PATH": "/bin:/usr/bin"},
        )
        self.assertEqual(out, "C k\n")

    def test_run_c_explicit_empty_env_is_not_os_environ(self):
        out = util.run_c(["/bin/sh", "-c", "echo [$HOME]"], env={})
        self.assertEqual(out, "[]\n")

    def test_bad_cwd_raises_command_error_naming_it(self):
        with self.assertRaises(util.CommandError) as ctx:
            util.run(["true"], cwd="/nonexistent-discover-dir")
        self.assertIn("/nonexistent-discover-dir", str(ctx.exception))
        with self.assertRaises(util.CommandError) as ctx:
            util.run(["true"], cwd="/etc/hosts")
        self.assertIn("/etc/hosts", str(ctx.exception))

    def test_not_installed_is_an_exception(self):
        self.assertTrue(issubclass(util.NotInstalled, Exception))


class HelpersTest(unittest.TestCase):
    def test_iso_utc_converts_to_z_suffix(self):
        dt = datetime(2026, 10, 14, 14, 0, 0, tzinfo=timezone(timedelta(hours=2)))
        self.assertEqual(util.iso_utc(dt), "2026-10-14T12:00:00Z")

    def test_read_text_missing_file_returns_none(self):
        self.assertIsNone(util.read_text("/nonexistent/discover/file"))

    def test_which(self):
        self.assertTrue(util.which("sh"))
        self.assertFalse(util.which("discover-no-such-command-xyz"))


class ReadRegularFileTest(unittest.TestCase):
    def test_on_linux_the_checked_inode_is_the_one_read(self):
        """No stat-then-open window: open O_PATH (inert for devices), check the
        descriptor, then reopen that same inode through /proc/self/fd."""
        import os
        import stat
        from unittest import mock

        calls = []

        def fake_open(path, flags, *args):
            calls.append((path, flags))
            return 10 + len(calls)

        regular = os.stat_result((stat.S_IFREG | 0o644, 0, 0, 1, 0, 0, 5, 0, 0, 0))
        with mock.patch.object(util.os, "O_PATH", 0o10000000, create=True), \
                mock.patch.object(util.os, "open", side_effect=fake_open), \
                mock.patch.object(util.os, "fstat", return_value=regular), \
                mock.patch.object(util.os, "stat", side_effect=AssertionError("path-based stat is racy")), \
                mock.patch.object(util.os, "read", return_value=b"hello"), \
                mock.patch.object(util.os, "close"):
            self.assertEqual(util.read_regular_file("/srv/site/.env"), "hello")

        self.assertEqual(calls[0][0], "/srv/site/.env")
        self.assertTrue(calls[0][1] & 0o10000000, "first open must be O_PATH")
        self.assertEqual(calls[1][0], "/proc/self/fd/11")

    def test_device_behind_o_path_is_never_reopened(self):
        import os
        import stat
        from unittest import mock

        device = os.stat_result((stat.S_IFCHR | 0o666, 0, 0, 1, 0, 0, 0, 0, 0, 0))
        with mock.patch.object(util.os, "O_PATH", 0o10000000, create=True), \
                mock.patch.object(util.os, "open", return_value=11) as opened, \
                mock.patch.object(util.os, "fstat", return_value=device), \
                mock.patch.object(util.os, "close"):
            self.assertIsNone(util.read_regular_file("/srv/site/.env"))
        self.assertEqual(opened.call_count, 1)


class EncodingTest(unittest.TestCase):
    def test_non_utf8_output_is_replaced_not_fatal(self):
        out = util.run(["printf", "\\377ok"])
        self.assertEqual(out, "\ufffdok")

    def test_non_utf8_stderr_of_a_failure_stays_a_command_error(self):
        with self.assertRaises(util.CommandError):
            util.run(["sh", "-c", "printf '\\377' >&2; exit 3"])

    def test_non_utf8_context_does_not_lose_the_sites(self):
        # sites.build_context reads `ss` through util.run: a process name in latin-1 must not raise.
        from discover_agent.collectors import sites

        output = util.run(["printf", 'LISTEN 0 1 0.0.0.0:80 0.0.0.0:* users:(("caf\\351",pid=1,fd=2))\\n'])
        sites.parse_ss(output)


class RunFullTest(unittest.TestCase):
    def test_returns_code_and_both_streams(self):
        self.assertEqual(
            util.run_full(["sh", "-c", "echo out; echo err >&2; exit 3"]), (3, "out\n", "err\n")
        )

    def test_passes_env_and_cwd(self):
        code, out, _ = util.run_full(
            ["sh", "-c", "pwd; echo $X"], env={"X": "v", "PATH": "/bin:/usr/bin"}, cwd="/"
        )
        self.assertEqual((code, out), (0, "/\nv\n"))

    def test_non_utf8_is_replaced(self):
        self.assertEqual(util.run_full(["printf", "\\377"])[1], "\ufffd")

    def test_missing_command(self):
        with self.assertRaises(util.CommandError) as ctx:
            util.run_full(["discover-no-such-command"])
        self.assertTrue(str(ctx.exception).startswith("command not found"))

    def test_timeout_is_a_distinct_command_error(self):
        for call in (util.run, util.run_full):
            with self.subTest(call=call.__name__), self.assertRaises(util.CommandTimeout) as ctx:
                call(["sleep", "5"], timeout=0.05)
            self.assertIsInstance(ctx.exception, util.CommandError)

    def test_bad_cwd(self):
        with self.assertRaises(util.CommandError):
            util.run_full(["true"], cwd="/nonexistent-discover-dir")


if __name__ == "__main__":
    unittest.main()
