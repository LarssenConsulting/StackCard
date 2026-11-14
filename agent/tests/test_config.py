import os
import tempfile
import unittest

from discover_agent import config


class AptRefreshHoursTest(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self._tmp.cleanup)
        self.paths = config.Paths(
            etc_dir=os.path.join(self._tmp.name, "etc"),
            state_dir=os.path.join(self._tmp.name, "var"),
        )

    def _write_conf(self, collect_lines):
        os.makedirs(self.paths.etc_dir, exist_ok=True)
        with open(self.paths.conf, "w") as fh:
            fh.write("[central]\nurl = https://central.example\n")
            if collect_lines is not None:
                fh.write("[collect]\n" + collect_lines + "\n")
        with open(self.paths.credentials, "w") as fh:
            fh.write("token\n")

    def test_default_constant(self):
        self.assertEqual(config.DEFAULT_APT_REFRESH_HOURS, 6)

    def test_load_reads_value(self):
        self._write_conf("apt_refresh_hours = 1")
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 1)

    def test_load_invalid_values_fall_back(self):
        for raw in ("0", "-3", "abc"):
            with self.subTest(raw=raw):
                self._write_conf("apt_refresh_hours = " + raw)
                self.assertEqual(config.load(self.paths).apt_refresh_hours, 6)
                self.assertEqual(config.read_apt_refresh_hours(self.paths), 6)

    def test_huge_value_is_clamped(self):
        self._write_conf("apt_refresh_hours = " + "9" * 400)
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 8760)
        self.assertEqual(config.read_apt_refresh_hours(self.paths), 8760)
        self._write_conf("apt_refresh_hours = 8760")
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 8760)

    def test_load_absent_falls_back(self):
        self._write_conf("weekly_interval_days = 7")
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 6)
        self._write_conf(None)
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 6)

    def test_save_then_load_roundtrip(self):
        cfg = config.Config(url="https://central.example", token="t", apt_refresh_hours=2)
        config.save(self.paths, cfg)
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 2)
        self.assertEqual(config.read_apt_refresh_hours(self.paths), 2)

    def test_read_without_conf_returns_default(self):
        self.assertEqual(config.read_apt_refresh_hours(self.paths), 6)

    def test_read_works_without_credentials(self):
        os.makedirs(self.paths.etc_dir)
        with open(self.paths.conf, "w") as fh:
            fh.write("[collect]\napt_refresh_hours = 4\n")
        self.assertIsNone(config.load(self.paths))
        self.assertEqual(config.read_apt_refresh_hours(self.paths), 4)

    def test_read_unreadable_conf_returns_default(self):
        os.makedirs(self.paths.etc_dir)
        with open(self.paths.conf, "wb") as fh:
            fh.write(b"\xff\xfe[[[ not ini")
        self.assertEqual(config.read_apt_refresh_hours(self.paths), 6)

    def test_weekly_interval_unchanged(self):
        self._write_conf("weekly_interval_days = 3\napt_refresh_hours = 2")
        cfg = config.load(self.paths)
        self.assertEqual(cfg.weekly_interval_days, 3)
        config.save(self.paths, cfg)
        self.assertEqual(config.load(self.paths).weekly_interval_days, 3)
        self.assertEqual(config.load(self.paths).apt_refresh_hours, 2)


if __name__ == "__main__":
    unittest.main()
