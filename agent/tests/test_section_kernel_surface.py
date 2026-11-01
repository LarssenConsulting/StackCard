import unittest

from discover_agent import util
from discover_agent.sections import kernel_surface

LSMOD = """\
Module                  Size  Used by
esp4                   24576  0
xfrm_user              61440  1
algif_aead             16384  0
"""
MODPROBE = """\
blacklist nouveau
install esp4 /bin/false
options snd foo=1
alias x y
"""


def make(files=None, lsmod=LSMOD, modprobe=MODPROBE, dirs=None, **overrides):
    files = {} if files is None else files

    def runner(cmd, **kw):
        if cmd[0] == "lsmod":
            if isinstance(lsmod, Exception):
                raise lsmod
            return lsmod
        if cmd[0] == "modprobe":
            if isinstance(modprobe, Exception):
                raise modprobe
            return modprobe
        raise AssertionError(cmd)

    values = dict(runner=runner, reader=files.get, listdir=(dirs or {}).get)
    values.update(overrides)
    return kernel_surface.collect(**values)


class SysctlTest(unittest.TestCase):
    def test_read_through_proc_sys(self):
        files = {"/proc/sys/kernel/kptr_restrict": "1\n", "/proc/sys/net/ipv4/ip_forward": "0\n"}
        result = make(files, sysctl=("kernel.kptr_restrict", "net.ipv4.ip_forward"))
        self.assertEqual(result.data["sysctl"], {"kernel.kptr_restrict": "1", "net.ipv4.ip_forward": "0"})

    def test_missing_sysctl_is_none_without_error(self):
        result = make({}, sysctl=("net.mptcp.enabled",))
        self.assertEqual(result.data["sysctl"], {"net.mptcp.enabled": None})
        self.assertEqual(result.source_errors, {})

    def test_defaults_cover_the_spec_list(self):
        result = make({})
        self.assertIn("net.mptcp.enabled", result.data["sysctl"])
        self.assertEqual(len(result.data["sysctl"]), 9)

    def test_name_cannot_escape_proc_sys(self):
        seen = []
        make({}, reader=lambda p: seen.append(p), sysctl=("../../etc/passwd", "a..b", "ok.name"))
        self.assertEqual(seen, ["/proc/sys/ok/name"])


class ModulesTest(unittest.TestCase):
    def test_lsmod_sorted(self):
        self.assertEqual(make().data["modules"], ["algif_aead", "esp4", "xfrm_user"])

    def test_lsmod_failure(self):
        result = make(lsmod=util.CommandError("command not found: lsmod"))
        self.assertIsNone(result.data["modules"])
        self.assertEqual(result.source_errors["modules"], "not_installed")


class ModprobeTest(unittest.TestCase):
    def test_filtered_and_sorted(self):
        self.assertEqual(make().data["modprobe"], ["blacklist nouveau", "install esp4 /bin/false"])

    def test_fallback_reads_modprobe_d(self):
        dirs = {"/etc/modprobe.d": ["a.conf", "readme.txt"], "/lib/modprobe.d": ["b.conf"]}
        files = {
            "/etc/modprobe.d/a.conf": "# blacklist commented\nblacklist foo\n  install bar /bin/true\noptions x=1\n",
            "/lib/modprobe.d/b.conf": "blacklist baz\n",
        }
        result = make(files, modprobe=util.CommandError("modprobe exited 1"), dirs=dirs)
        self.assertEqual(result.data["modprobe"],
                         ["blacklist baz", "blacklist foo", "install bar /bin/true"])

    def test_fallback_with_no_directory_is_unread(self):
        result = make(modprobe=util.CommandError("modprobe exited 1"), dirs={})
        self.assertIsNone(result.data["modprobe"])
        self.assertIn("modprobe", result.source_errors)
