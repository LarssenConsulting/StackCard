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


if __name__ == "__main__":
    unittest.main()
