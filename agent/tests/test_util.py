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


if __name__ == "__main__":
    unittest.main()
