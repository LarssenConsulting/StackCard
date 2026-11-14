import os
import unittest
from datetime import datetime, timezone
from unittest import mock

from discover_agent import sections, util
from discover_agent.sections import apt_updates

FIXTURES = os.path.join(os.path.dirname(__file__), "fixtures", "apt")


def fixture(name):
    with open(os.path.join(FIXTURES, name), encoding="utf-8") as fh:
        return fh.read()


SIMULATION = fixture("dist-upgrade-24.04.txt")
HELD = fixture("showhold.txt")
NOW = "2026-11-14T12:00:00Z"
NOW_TS = datetime(2026, 11, 14, 12, 0, tzinfo=timezone.utc).timestamp()
UPDATE = ["/usr/bin/apt-get", "update", "-q", "-o", "DPkg::Lock::Timeout=0"]
SIM = ["/usr/bin/apt-get", "-s", "-o", "Debug::NoLocking=1", "dist-upgrade"]
HOLD = ["/usr/bin/apt-mark", "showhold"]
ALLOWED = (UPDATE, SIM, HOLD)
CLEAN = {"LC_ALL": "C", "LANGUAGE": "C", "PATH": "/usr/sbin:/usr/bin:/sbin:/bin"}
MARKER = "/state/apt-refresh-attempt"
STAMP = "/var/lib/apt/periodic/update-success-stamp"


class Host:
    """Fake system: records every command; asserts none is outside the allowed three."""

    def __init__(self, age_hours=2, update=(0, "", ""), sim=SIMULATION, held=HELD, update_exc=None,
                 marker_age=None, lists_age=None):
        self.age_hours = age_hours
        self.marker_age = marker_age
        self.lists_age = lists_age
        self.markers = []
        self.envs = []
        self.update = update
        self.update_exc = update_exc
        self.sim = sim
        self.held = held
        self.commands = []

    def runner(self, cmd, **kw):
        self.commands.append(list(cmd))
        self.envs.append(kw.get("env"))
        if cmd == SIM:
            return self.sim
        if cmd == HOLD:
            return self.held
        raise AssertionError(f"unexpected command {cmd}")

    def run_full(self, cmd, timeout=None, env=None, **kw):
        self.commands.append(list(cmd))
        self.update_env = env
        self.update_timeout = timeout
        if self.update_exc:
            raise self.update_exc
        return self.update

    def stat(self, path):
        age = {STAMP: self.age_hours, MARKER: self.marker_age,
               "/var/lib/apt/lists": self.lists_age}.get(path)
        if age is None:
            raise FileNotFoundError(path)
        return mock.Mock(st_mtime=NOW_TS - age * 3600)

    def collect(self, **kw):
        kw.setdefault("refresh_hours", 6)
        return apt_updates.collect(
            runner=self.runner, run_full=self.run_full, now=lambda: NOW,
            stat=self.stat, listdir=lambda p: [], marker_path=MARKER,
            write_marker=lambda path, text: self.markers.append((path, text)), **kw,
        )


def by_name(rows):
    return {r["package"]: r for r in rows}


class ParseSimulationTest(unittest.TestCase):
    def setUp(self):
        self.rows = apt_updates.parse_simulation(SIMULATION)
        self.by = by_name(self.rows)

    def test_openssl_is_a_security_upgrade(self):
        self.assertEqual(self.by["openssl"], {
            "package": "openssl", "arch": "amd64", "installed": "3.0.13-0ubuntu3.4",
            "candidate": "3.0.13-0ubuntu3.5", "origins": ["noble-security", "noble-updates"],
            "security": True,
        })

    def test_updates_only_is_not_security(self):
        self.assertFalse(self.by["tzdata"]["security"])
        self.assertEqual(self.by["tzdata"]["origins"], ["noble-updates"])
        self.assertEqual(self.by["tzdata"]["arch"], "all")

    def test_new_kernel_has_no_installed_version(self):
        kernel = self.by["linux-image-6.8.0-110-generic"]
        self.assertIsNone(kernel["installed"])
        self.assertEqual(kernel["candidate"], "6.8.0-110.110")

    def test_trailing_bracket_after_the_inst_line_is_accepted(self):
        self.assertEqual(self.by["libcurl4t64"]["candidate"], "8.5.0-2ubuntu10.6")

    def test_conf_and_remv_are_ignored_and_result_is_sorted(self):
        self.assertEqual([r["package"] for r in self.rows], sorted(by_name(self.rows)))
        self.assertEqual(len(self.rows), 6)
        self.assertNotIn("libfoo-obsolete1", self.by)

    def test_empty_output(self):
        self.assertEqual(apt_updates.parse_simulation(""), [])

    def test_security_suite_variants_and_bare_origin(self):
        text = (
            "Inst a [1] (2 Ubuntu:24.04/noble-apps-security [amd64])\n"
            "Inst b [1] (2 Ubuntu:24.04/noble-infra-security [amd64])\n"
            "Inst c [1] (2 Docker:noble [amd64])\n"
            "Inst d [1] (2 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-updates [amd64])\n"
            "Inst nodejs [22.23.4-1nodesource1] (22.23.5-1nodesource1 . nodistro:nodistro [amd64])\n"
            "Inst apache2 [2.4.69-1] (2.4.71-1 LP-PPA-example-apache2:24.04/noble [amd64])\n"
        )
        by = by_name(apt_updates.parse_simulation(text))
        self.assertTrue(by["a"]["security"])
        self.assertTrue(by["b"]["security"])
        self.assertEqual(by["c"]["origins"], ["noble"])
        self.assertEqual(by["nodejs"]["origins"], ["nodistro"])
        self.assertFalse(by["nodejs"]["security"])
        self.assertEqual(by["apache2"]["origins"], ["noble"])
        self.assertFalse(by["c"]["security"])
        self.assertEqual(by["d"]["origins"], ["noble-updates"])

    def test_sorted_by_package_then_arch(self):
        text = (
            "Inst z [1] (2 Ubuntu:24.04/noble-updates [amd64])\n"
            "Inst a:i386 [1] (2 Ubuntu:24.04/noble-updates [i386])\n"
            "Inst a [1] (2 Ubuntu:24.04/noble-updates [amd64])\n"
        )
        rows = apt_updates.parse_simulation(text)
        self.assertEqual([(r["package"], r["arch"]) for r in rows], [("a", "amd64"), ("a", "i386"), ("z", "amd64")])


class ParseHeldTest(unittest.TestCase):
    def test_sorted_and_blank_lines_ignored(self):
        self.assertEqual(apt_updates.parse_held(HELD), ["libssl3t64", "linux-image-generic", "nginx"])
        self.assertEqual(apt_updates.parse_held(""), [])


class CacheUpdatedAtTest(unittest.TestCase):
    def test_stamp_wins(self):
        seen = []

        def stat(path):
            seen.append(path)
            return mock.Mock(st_mtime=1000)
        got = apt_updates.cache_updated_at(stat, lambda p: self.fail("lists must not be read"))
        self.assertEqual(got, datetime.fromtimestamp(1000, timezone.utc))
        self.assertEqual(seen, ["/var/lib/apt/periodic/update-success-stamp"])

    def test_falls_back_to_newest_release_file(self):
        times = {"/var/lib/apt/lists/a_InRelease": 100, "/var/lib/apt/lists/b_Release": 300,
                 "/var/lib/apt/lists/c_Packages": 900}

        def stat(path):
            if path not in times:
                raise FileNotFoundError(path)
            return mock.Mock(st_mtime=times[path])
        got = apt_updates.cache_updated_at(stat, lambda p: ["a_InRelease", "b_Release", "c_Packages", "lock"])
        self.assertEqual(got, datetime.fromtimestamp(300, timezone.utc))

    def test_none_when_nothing(self):
        def stat(path):
            raise FileNotFoundError(path)

        def listdir(path):
            raise FileNotFoundError(path)
        self.assertIsNone(apt_updates.cache_updated_at(stat, listdir))
        self.assertIsNone(apt_updates.cache_updated_at(stat, lambda p: []))


class CollectTest(unittest.TestCase):
    def test_fresh_cache_does_not_update(self):
        host = Host(age_hours=2)
        result = host.collect()
        self.assertNotIn(UPDATE, host.commands)
        self.assertEqual(result.source_errors, {})
        self.assertEqual(set(result.data), {"cache_updated_at", "upgrades", "held"})
        self.assertEqual(result.data["cache_updated_at"], "2026-11-14T10:00:00Z")
        self.assertEqual(result.data["held"], ["libssl3t64", "linux-image-generic", "nginx"])
        self.assertEqual(len(result.data["upgrades"]), 6)

    def test_stale_cache_updates_once_with_a_clean_environment(self):
        host = Host(age_hours=7)
        host.collect()
        self.assertEqual(host.commands.count(UPDATE), 1)
        self.assertEqual(host.update_timeout, 120)
        self.assertEqual(host.update_env, CLEAN)
        self.assertEqual(host.markers, [(MARKER, NOW)])

    def test_unknown_cache_updates(self):
        host = Host(age_hours=None)
        result = host.collect()
        self.assertEqual(host.commands.count(UPDATE), 1)
        self.assertIsNone(result.data["cache_updated_at"])

    def test_locked_update_is_reported_and_upgrades_still_read(self):
        host = Host(age_hours=7, update=(100, "", "E: Could not get lock /var/lib/apt/lists/lock\n"))
        result = host.collect()
        self.assertEqual(result.source_errors, {"apt_update": "locked"})
        self.assertEqual(len(result.data["upgrades"]), 6)
        self.assertEqual(host.commands.count(UPDATE), 1)

    def test_held_by_process_is_locked_too(self):
        host = Host(age_hours=7, update=(100, "", "E: ... It is held by process 1234 (apt-get)\n"))
        self.assertEqual(host.collect().source_errors, {"apt_update": "locked"})

    def test_other_failure_gives_a_short_message(self):
        host = Host(age_hours=7, update=(100, "", "E: Failed to fetch https://example.org/ Temporary failure\n"))
        message = host.collect().source_errors["apt_update"]
        self.assertIn("exited 100", message)
        self.assertIn("Failed to fetch", message)

    def test_failure_message_keeps_the_first_e_line_only(self):
        err = "Err:1 https://example.org noble InRelease\n  503 Service Unavailable\nE: Failed to fetch X\nE: other\n"
        message = Host(age_hours=7, update=(100, "", err)).collect().source_errors["apt_update"]
        self.assertEqual(message, "apt-get update exited 100: E: Failed to fetch X")

    def test_failure_message_falls_back_to_first_non_empty_line(self):
        message = Host(age_hours=7, update=(1, "", "\n  boom\nmore\n")).collect().source_errors["apt_update"]
        self.assertEqual(message, "apt-get update exited 1: boom")

    def test_failed_refresh_is_not_retried_before_the_delay(self):
        # Stamp stale (broken repository), but the agent tried 1 h ago.
        host = Host(age_hours=30, marker_age=1)
        host.collect()
        self.assertNotIn(UPDATE, host.commands)
        self.assertEqual(host.markers, [])

    def test_old_attempt_and_old_cache_refresh_again(self):
        host = Host(age_hours=30, marker_age=7)
        host.collect()
        self.assertEqual(host.commands.count(UPDATE), 1)

    def test_recent_attempt_does_not_hide_a_fresh_cache(self):
        host = Host(age_hours=1, marker_age=20)
        host.collect()
        self.assertNotIn(UPDATE, host.commands)

    def test_missing_stamp_uses_the_lists_directory_mtime(self):
        host = Host(age_hours=None, lists_age=2)
        result = host.collect()
        self.assertNotIn(UPDATE, host.commands)
        self.assertEqual(result.data["cache_updated_at"], "2026-11-14T10:00:00Z")

    def test_future_cache_is_stale(self):
        host = Host(age_hours=-5)
        host.collect()
        self.assertEqual(host.commands.count(UPDATE), 1)

    def test_future_marker_counts_as_absent(self):
        host = Host(age_hours=30, marker_age=-5)
        host.collect()
        self.assertEqual(host.commands.count(UPDATE), 1)

    def test_simulation_and_showhold_use_the_clean_environment(self):
        host = Host()
        host.collect()
        self.assertEqual(host.envs, [CLEAN, CLEAN])

    def test_unparsed_inst_lines_are_counted(self):
        sim = SIMULATION + "Inst weird line without version\nInst other ???\n"
        result = Host(sim=sim).collect()
        self.assertEqual(result.source_errors, {"upgrades_unparsed": "2 lignes non lues"})
        self.assertEqual(len(result.data["upgrades"]), 6)

    def test_all_lines_parsed_gives_no_unparsed_error(self):
        self.assertNotIn("upgrades_unparsed", Host().collect().source_errors)

    def test_timeout(self):
        host = Host(age_hours=7, update_exc=util.CommandTimeout("timeout after 120s"))
        result = host.collect()
        self.assertEqual(result.source_errors, {"apt_update": "timeout"})
        self.assertEqual(host.commands.count(UPDATE), 1)
        self.assertEqual(len(result.data["upgrades"]), 6)

    def test_refresh_hours_none_reads_the_config(self):
        host = Host(age_hours=7)
        with mock.patch.object(apt_updates.config, "read_apt_refresh_hours", return_value=12) as read:
            apt_updates.collect(
                runner=host.runner, run_full=host.run_full, now=lambda: NOW,
                refresh_hours=None, stat=host.stat, listdir=lambda p: [], marker_path=MARKER,
                write_marker=lambda *a: None,
            )
        read.assert_called_once()
        self.assertNotIn(UPDATE, host.commands)  # 7 h < 12 h

    def test_simulation_failure_is_a_source_error(self):
        host = Host()

        def runner(cmd, **kw):
            if cmd == SIM:
                raise util.CommandError("apt-get exited 100: E: broken")
            return host.runner(cmd, **kw)
        result = apt_updates.collect(
            runner=runner, run_full=host.run_full, now=lambda: NOW, refresh_hours=6,
            stat=host.stat, listdir=lambda p: [], marker_path=MARKER, write_marker=lambda *a: None,
        )
        self.assertIsNone(result.data["upgrades"])
        self.assertIn("broken", result.source_errors["upgrades"])
        self.assertEqual(result.data["held"], ["libssl3t64", "linux-image-generic", "nginx"])

    def test_missing_apt_is_not_installed(self):
        def runner(cmd, **kw):
            raise util.CommandError(f"command not found: {cmd[0]}")
        host = Host(age_hours=7, update_exc=util.CommandError("command not found: /usr/bin/apt-get"))
        with self.assertRaises(util.NotInstalled):
            apt_updates.collect(
                runner=runner, run_full=host.run_full, now=lambda: NOW, refresh_hours=6,
                stat=host.stat, listdir=lambda p: [], marker_path=MARKER,
                write_marker=lambda path, text: host.markers.append((path, text)),
            )

    def test_only_allowed_commands_are_ever_run(self):
        hosts = [Host(age_hours=h) for h in (1, 7, None)]
        hosts.append(Host(age_hours=7, update=(100, "", "E: Could not get lock")))
        for host in hosts:
            host.collect()
            for cmd in host.commands:
                self.assertIn(cmd, ALLOWED)
                self.assertTrue(cmd[0] in ("/usr/bin/apt-get", "/usr/bin/apt-mark"))
                for verb in ("install", "remove", "purge", "upgrade", "dpkg"):
                    self.assertNotIn(verb, cmd)
            if SIM in host.commands:
                self.assertIn("-s", SIM)


class RegistrationTest(unittest.TestCase):
    def test_timeout(self):
        self.assertEqual(apt_updates.REFRESH_TIMEOUT, 120)
        self.assertEqual(apt_updates.SIMULATION_TIMEOUT, 60)
        self.assertEqual(apt_updates.SHOWHOLD_TIMEOUT, 15)
        self.assertEqual(sections.TIMEOUT_APT, 210)
        self.assertEqual(sections.timeout_for("apt_updates"), 210)
        self.assertGreaterEqual(
            sections.TIMEOUT_APT,
            apt_updates.REFRESH_TIMEOUT + apt_updates.SIMULATION_TIMEOUT + apt_updates.SHOWHOLD_TIMEOUT + 15,
        )

    def test_hourly_and_positioned_after_apt_sources(self):
        names = sections.SECTION_NAMES
        self.assertEqual(names.index("apt_updates"), names.index("apt_sources") + 1)
        self.assertNotIn("apt_updates", sections.WEEKLY)

    def test_registered(self):
        self.assertIs(sections.REGISTRY["apt_updates"], apt_updates.collect)


if __name__ == "__main__":
    unittest.main()
