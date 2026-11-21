import unittest

from discover_agent import util
from discover_agent.sections import REGISTRY, SECTION_NAMES, mounts

FINDMNT = (
    "/ /dev/mapper/ubuntu--vg-ubuntu--lv ext4 rw,relatime\n"
    "/boot/efi /dev/sda1 vfat rw,relatime,fmask=0077,dmask=0077\n"
    "/boot /dev/sda2 ext4 rw,relatime\n"
    "/srv/my\\x20data /dev/sdb1 xfs ro,relatime,attr2\n"
)


def fake(code=0, out=FINDMNT, err="", calls=None):
    def run_full(cmd, **kw):
        if calls is not None:
            calls.append((cmd, kw))
        if isinstance(code, Exception):
            raise code
        return code, out, err
    return run_full


class MountsTest(unittest.TestCase):
    def test_registered_as_an_hourly_section(self):
        self.assertIs(REGISTRY["mounts"], mounts.collect)
        self.assertIn("mounts", SECTION_NAMES)

    def test_runs_findmnt_on_local_filesystem_types_in_the_c_locale(self):
        calls = []
        mounts.collect(run_full=fake(calls=calls))
        cmd, kw = calls[0]
        self.assertEqual(cmd, ["/usr/bin/findmnt", "-rno", "TARGET,SOURCE,FSTYPE,OPTIONS", "-t", "ext4,xfs,btrfs,vfat"])
        self.assertEqual(kw["env"]["LC_ALL"], "C")

    def test_rows_sorted_by_target_with_options_and_read_only_flag(self):
        result = mounts.collect(run_full=fake())
        self.assertEqual(result.source_errors, {})
        rows = result.data["filesystems"]
        self.assertEqual([r["target"] for r in rows], ["/", "/boot", "/boot/efi", "/srv/my data"])
        self.assertEqual(rows[2], {
            "target": "/boot/efi", "source": "/dev/sda1", "fstype": "vfat",
            "options": ["rw", "relatime", "fmask=0077", "dmask=0077"], "read_only": False,
        })
        self.assertTrue(rows[3]["read_only"])

    def test_nothing_mounted_is_an_empty_list(self):
        result = mounts.collect(run_full=fake(code=1, out=""))
        self.assertEqual(result.data, {"filesystems": []})
        self.assertEqual(result.source_errors, {})

    def test_failure_is_null_with_a_source_error(self):
        result = mounts.collect(run_full=fake(code=1, out="", err="findmnt: bad usage"))
        self.assertIsNone(result.data["filesystems"])
        self.assertIn("bad usage", result.source_errors["findmnt"])

    def test_missing_findmnt_is_not_installed(self):
        result = mounts.collect(run_full=fake(code=util.CommandError("command not found: /usr/bin/findmnt")))
        self.assertIsNone(result.data["filesystems"])
        self.assertEqual(result.source_errors["findmnt"], "not_installed")

    def test_unexpected_line_is_reported_not_guessed(self):
        result = mounts.collect(run_full=fake(out="/ /dev/sda1 ext4\n"))
        self.assertIsNone(result.data["filesystems"])
        self.assertIn("unexpected findmnt line", result.source_errors["findmnt"])

    def test_unescape_keeps_utf8(self):
        self.assertEqual(mounts.unescape("/mnt/caf\\xc3\\xa9"), "/mnt/café")


if __name__ == "__main__":
    unittest.main()
