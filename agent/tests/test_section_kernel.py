import json
import unittest

from discover_agent import util
from discover_agent.sections import kernel

PACKAGES = (
    "linux-image-6.8.0-147-generic\t6.8.0-147\tamd64\tlinux\t6.8.0-147\tii \n"
    "linux-image-6.8.0-100-generic\t6.8.0-100\tamd64\tlinux\t6.8.0-100\tii \n"
    "linux-image-generic\t6.8.0\tamd64\tlinux-meta\t6.8.0\tii \n"
    "zlib1g\t1\tamd64\tzlib\t1\tii \n"
)

LIVEPATCH = json.dumps({
    "Machine-Id": "abcdef0123456789",
    "Token": "SECRET-TOKEN",
    "Status": [{
        "Kernel": "6.8.0-147.147-generic",
        "Running": True,
        "Uuid": "uuid-1",
        "Livepatch": {
            "CheckState": "checked",
            "State": "applied",
            "Version": "103.1",
            "Fixes": [
                {"Name": "cve-2026-2", "Patched": True, "Description": "b", "Bug": "lp:1"},
                {"Name": "cve-2026-1", "Patched": False, "Description": "a", "Bug": "lp:2"},
            ],
        },
    }],
})

STAT = "cpu  1 2 3\nbtime 1700000000\nprocesses 9\n"


def make(files=None, livepatch=LIVEPATCH, **overrides):
    files = {"/proc/stat": STAT} if files is None else files

    def runner(cmd, **kw):
        if cmd[0] == "uname":
            return "6.8.0-147-generic\n"
        if cmd[0] == "dpkg-query":
            return PACKAGES
        if cmd[0] == "canonical-livepatch":
            if isinstance(livepatch, Exception):
                raise livepatch
            return livepatch
        raise AssertionError(cmd)

    values = dict(runner=runner, reader=files.get)
    values.update(overrides)
    return kernel.collect(**values)


class KernelTest(unittest.TestCase):
    def test_running_and_installed(self):
        data = make().data
        self.assertEqual(data["running"], "6.8.0-147-generic")
        self.assertEqual(data["installed"], ["6.8.0-100-generic", "6.8.0-147-generic"])

    def test_reboot_not_required_when_file_absent(self):
        data = make().data
        self.assertIs(data["reboot_required"], False)
        self.assertEqual(data["reboot_required_pkgs"], [])

    def test_reboot_required_with_packages(self):
        files = {"/proc/stat": STAT, "/var/run/reboot-required": "*** System restart required ***\n",
                 "/var/run/reboot-required.pkgs": "linux-image-6.8.0-150\nlibc6\nlibc6\n"}
        data = make(files).data
        self.assertIs(data["reboot_required"], True)
        self.assertEqual(data["reboot_required_pkgs"], ["libc6", "linux-image-6.8.0-150"])

    def test_read_error_on_reboot_required_is_null_with_source_error(self):
        def reader(path):
            if path.startswith("/var/run/reboot-required"):
                raise PermissionError(path)
            return STAT

        result = make(reader=reader)
        self.assertIsNone(result.data["reboot_required"])
        self.assertIn("reboot_required", result.source_errors)
        self.assertIsNone(result.data["reboot_required_pkgs"])
        self.assertIn("reboot_required_pkgs", result.source_errors)

    def test_boot_time_from_btime_in_utc(self):
        self.assertEqual(make().data["boot_time"], "2023-11-14T22:13:20Z")

    def test_boot_time_unreadable(self):
        result = make({})
        self.assertIsNone(result.data["boot_time"])
        self.assertIn("boot_time", result.source_errors)

    def test_livepatch_absent(self):
        result = make(livepatch=util.CommandError("command not found: canonical-livepatch"))
        self.assertIsNone(result.data["livepatch"])
        self.assertEqual(result.source_errors["livepatch"], "not_installed")

    def test_livepatch_failure_keeps_message(self):
        result = make(livepatch=util.CommandError("canonical-livepatch status exited 1: not enabled"))
        self.assertIsNone(result.data["livepatch"])
        self.assertIn("not enabled", result.source_errors["livepatch"])

    def test_livepatch_whitelist(self):
        result = make()
        lp = result.data["livepatch"]
        self.assertEqual(lp, {
            "kernel": "6.8.0-147.147-generic", "running": True, "check_state": "checked",
            "patch_state": "applied", "version": "103.1",
            "fixes": [{"name": "cve-2026-1", "patched": False}, {"name": "cve-2026-2", "patched": True}],
        })
        text = json.dumps(result.data)
        for forbidden in ("Machine", "abcdef", "SECRET-TOKEN", "uuid-1", "lp:1"):
            self.assertNotIn(forbidden, text)

    def test_livepatch_without_status_is_an_error(self):
        result = make(livepatch="{}")
        self.assertIsNone(result.data["livepatch"])
        self.assertIn("livepatch", result.source_errors)
