import io
import os
import sys
import tarfile
import tempfile
import unittest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), "..", "packaging"))
import build_deb  # noqa: E402

from discover_agent import __version__  # noqa: E402


def read_ar(path):
    with open(path, "rb") as fh:
        data = fh.read()
    assert data[:8] == b"!<arch>\n", "not an ar archive"
    members, pos = {}, 8
    while pos < len(data):
        header = data[pos:pos + 60]
        name = header[:16].decode().strip().rstrip("/")
        size = int(header[48:58].decode().strip())
        members[name] = data[pos + 60:pos + 60 + size]
        pos += 60 + size + (size % 2)
    return members


class BuildDebTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.deb = build_deb.build(cls.tmp.name)
        cls.members = read_ar(cls.deb)

    @classmethod
    def tearDownClass(cls):
        cls.tmp.cleanup()

    def tar(self, name):
        return tarfile.open(fileobj=io.BytesIO(self.members[name]), mode="r:gz")

    def test_file_name_and_member_order(self):
        self.assertEqual(os.path.basename(self.deb), f"discover-agent_{__version__}_all.deb")
        self.assertEqual(list(self.members), ["debian-binary", "control.tar.gz", "data.tar.gz"])
        self.assertEqual(self.members["debian-binary"], b"2.0\n")

    def test_control_fields_and_maintainer_scripts(self):
        with self.tar("control.tar.gz") as tar:
            control = tar.extractfile("./control").read().decode()
            modes = {m.name: m.mode for m in tar.getmembers() if m.isfile()}
        self.assertIn("Package: discover-agent\n", control)
        self.assertIn(f"Version: {__version__}\n", control)
        self.assertIn("Architecture: all\n", control)
        self.assertIn("Depends: python3 (>= 3.10), iproute2, openssl\n", control)
        for script in ("./postinst", "./prerm", "./postrm"):
            self.assertEqual(modes[script], 0o755, script)

    def test_payload_layout_owner_and_modes(self):
        with self.tar("data.tar.gz") as tar:
            members = {m.name: m for m in tar.getmembers()}
        expected = [
            "./usr/bin/discover-agent",
            "./usr/lib/discover-agent/discover_agent/cli.py",
            "./usr/lib/discover-agent/discover_agent/collectors/apache.py",
            "./lib/systemd/system/discover-agent.service",
            "./lib/systemd/system/discover-agent.timer",
        ]
        for name in expected:
            self.assertIn(name, members)
        self.assertEqual(members["./usr/bin/discover-agent"].mode, 0o755)
        self.assertEqual(members["./usr/lib/discover-agent/discover_agent/cli.py"].mode, 0o644)
        self.assertTrue(all(m.uid == 0 and m.gid == 0 for m in members.values()))
        self.assertFalse(any("__pycache__" in name or name.endswith(".pyc") for name in members))
        self.assertFalse(any("/tests" in name for name in members))


if __name__ == "__main__":
    unittest.main()
