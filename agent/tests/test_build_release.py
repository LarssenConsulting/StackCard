import hashlib
import os
import stat
import sys
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "packaging"))
import build_release  # noqa: E402

from discover_agent import __version__  # noqa: E402

PACKAGING = os.path.join(os.path.dirname(__file__), "..", "packaging")


def read(path):
    with open(path, "rb") as fh:
        return fh.read()


class BuildReleaseTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.out = build_release.build(self.tmp.name)

    def test_publishes_exactly_the_files_install_sh_downloads(self):
        self.assertEqual(sorted(os.listdir(self.out)), sorted([
            "install.sh",
            f"discover-agent_{__version__}_all.deb",
            "discover-agent_latest_all.deb",
            "discover-agent_latest_all.deb.sha256",
            "version.txt",
        ]))

    def test_version_file_holds_the_packaged_version(self):
        self.assertEqual(read(os.path.join(self.out, "version.txt")), f"{__version__}\n".encode())

    def test_latest_is_the_versioned_package_with_a_matching_checksum(self):
        latest = read(os.path.join(self.out, "discover-agent_latest_all.deb"))
        self.assertEqual(latest, read(os.path.join(self.out, f"discover-agent_{__version__}_all.deb")))
        line = read(os.path.join(self.out, "discover-agent_latest_all.deb.sha256")).decode()
        self.assertEqual(line, f"{hashlib.sha256(latest).hexdigest()}  discover-agent_latest_all.deb\n")

    def test_install_script_is_copied_executable(self):
        script = os.path.join(self.out, "install.sh")
        self.assertEqual(read(script), read(os.path.join(PACKAGING, "install.sh")))
        self.assertTrue(os.stat(script).st_mode & stat.S_IXUSR)

    def test_rebuild_replaces_previous_files(self):
        stale = os.path.join(self.out, "discover-agent_0.0.1_all.deb")
        with open(stale, "wb") as fh:
            fh.write(b"old")
        build_release.build(self.tmp.name)
        self.assertFalse(os.path.exists(stale))


if __name__ == "__main__":
    unittest.main()
