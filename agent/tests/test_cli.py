import contextlib
import io
import json
import os
import ssl
import stat
import tempfile
import threading
import unittest
from unittest import mock

from discover_agent import cli, config, sections

SNAPSHOT = {"schema_version": 1, "server": {"hostname": "web01"}, "errors": []}
SNAPSHOT_WITH_WEEKLY = {
    **SNAPSHOT,
    "sections": {
        "integrity": {"collected_at": "2026-11-01T08:00:00Z", "error": None, "source_errors": {},
                      "data": [], "sha256": "h"},
        "code_patterns": {"error": "skipped_not_due"},
    },
}
IDENTITY = {"machine_id": "abc123", "hostname": "web01"}


class CliTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.paths = config.Paths(
            etc_dir=os.path.join(self.tmp.name, "etc"),
            state_dir=os.path.join(self.tmp.name, "state"),
        )
        patches = [
            mock.patch.object(config, "default_paths", return_value=self.paths),
            mock.patch.object(cli, "identity", return_value=IDENTITY),
            mock.patch.object(cli, "enable_timer"),
            mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT),
        ]
        for patch in patches:
            patch.start()
            self.addCleanup(patch.stop)
        self.post = mock.patch.object(cli.transport, "post_json").start()
        self.addCleanup(mock.patch.stopall)

    def tearDown(self):
        self.tmp.cleanup()

    def run_cli(self, *argv):
        out, err = io.StringIO(), io.StringIO()
        with contextlib.redirect_stdout(out), contextlib.redirect_stderr(err):
            code = cli.main(list(argv))
        return code, out.getvalue(), err.getvalue()

    def enroll(self):
        self.post.side_effect = [(201, {"server_id": 7, "token": "7|secret-token"}), (200, {"status": "ok"})]
        return self.run_cli(
            "enroll", "--url", "https://central.example.com/", "--token", "enroll-tok",
            "--ca-file", "/etc/ssl/ca.pem", "--pin-sha256", "PIN=",
        )

    def test_enroll_saves_protected_credentials_and_sends_first_inventory(self):
        code, out, _ = self.enroll()
        self.assertEqual(code, 0)
        first = self.post.call_args_list[0]
        self.assertEqual(first.args[0], "https://central.example.com/api/v1/enroll")
        self.assertEqual(
            first.args[1],
            {"enrollment_token": "enroll-tok", "machine_id": "abc123", "hostname": "web01"},
        )
        self.assertEqual(first.kwargs["ca_file"], "/etc/ssl/ca.pem")
        self.assertEqual(first.kwargs["pin_sha256"], "PIN=")

        creds = os.path.join(self.paths.etc_dir, "credentials")
        self.assertEqual(stat.S_IMODE(os.stat(creds).st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(os.stat(os.path.join(self.paths.etc_dir, "agent.conf")).st_mode), 0o600)
        loaded = config.load(self.paths)
        self.assertEqual(loaded.url, "https://central.example.com")
        self.assertEqual(loaded.token, "7|secret-token")
        cli.enable_timer.assert_called_once()

        second = self.post.call_args_list[1]
        self.assertEqual(second.args[0], "https://central.example.com/api/v1/inventory")
        self.assertEqual(second.kwargs["token"], "7|secret-token")
        self.assertNotIn("secret-token", out)

    def test_enroll_rejected_keeps_nothing(self):
        self.post.side_effect = [(410, {"message": "Jeton invalide ou expiré"})]
        code, _, err = self.run_cli("enroll", "--url", "https://c.example.com", "--token", "bad")
        self.assertEqual(code, 1)
        self.assertIn("410", err)
        self.assertFalse(os.path.exists(os.path.join(self.paths.etc_dir, "credentials")))

    def test_enroll_reads_the_token_from_stdin(self):
        self.post.side_effect = [(201, {"server_id": 7, "token": "7|secret-token"}), (200, {"status": "ok"})]
        with mock.patch("sys.stdin", io.StringIO("enroll-tok\n")):
            code, _, _ = self.run_cli("enroll", "--url", "https://c.example.com", "--token", "-")
        self.assertEqual(code, 0)
        self.assertEqual(self.post.call_args_list[0].args[1]["enrollment_token"], "enroll-tok")

    def test_enroll_refuses_an_empty_token_from_stdin(self):
        with mock.patch("sys.stdin", io.StringIO("\n")):
            code, _, err = self.run_cli("enroll", "--url", "https://c.example.com", "--token", "-")
        self.assertEqual(code, 1)
        self.assertIn("jeton", err)
        self.post.assert_not_called()

    def test_enroll_keeps_credentials_when_only_the_first_inventory_fails(self):
        self.post.side_effect = [(201, {"server_id": 7, "token": "7|secret-token"}), (500, {"message": "boom"})]
        code, _, err = self.run_cli("enroll", "--url", "https://c.example.com", "--token", "t")
        self.assertEqual(code, cli.EXIT_ENROLLED_SEND_FAILED)
        self.assertEqual(config.load(self.paths).token, "7|secret-token")
        self.assertIn("500", err)

    def test_enroll_refuses_plain_http(self):
        code, _, err = self.run_cli("enroll", "--url", "http://c.example.com", "--token", "t")
        self.assertEqual(code, 1)
        self.assertIn("https", err)
        self.post.assert_not_called()

    def test_run_without_enrollment(self):
        code, _, err = self.run_cli("run")
        self.assertEqual(code, 2)
        self.assertIn("enroll", err)

    def test_run_records_success_in_state(self):
        self.enroll()
        self.post.side_effect = None
        self.post.return_value = (200, {"status": "ok"})
        code, _, _ = self.run_cli("run")
        self.assertEqual(code, 0)
        state = config.load_state(self.paths)
        self.assertEqual(state["last_status"], 200)
        self.assertIsNotNone(state["last_success_at"])
        self.assertIsNone(state["last_error"])

    def test_run_records_http_failure(self):
        self.enroll()
        self.post.side_effect = None
        self.post.return_value = (401, {"message": "Unauthenticated."})
        code, _, _ = self.run_cli("run")
        self.assertEqual(code, 1)
        self.assertIn("401", config.load_state(self.paths)["last_error"])

    def test_run_records_transport_exception(self):
        self.enroll()
        self.post.side_effect = ssl.SSLError("certificate verify failed")
        code, _, err = self.run_cli("run")
        self.assertEqual(code, 1)
        self.assertIn("certificate verify failed", config.load_state(self.paths)["last_error"])
        self.assertIn("certificate verify failed", err)

    def test_status_never_prints_the_token(self):
        self.enroll()
        code, out, _ = self.run_cli("status")
        self.assertEqual(code, 0)
        self.assertIn("https://central.example.com", out)
        self.assertNotIn("secret-token", out)

    def test_collect_prints_snapshot_json(self):
        code, out, _ = self.run_cli("collect")
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(out), SNAPSHOT)

    def test_send_failure_does_not_record_weekly(self):
        self.enroll()
        self.post.side_effect = None
        self.post.return_value = (500, {"message": "boom"})
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT_WITH_WEEKLY):
            code, _, _ = self.run_cli("run")
        self.assertEqual(code, 1)
        self.assertNotIn("next_run", config.load_state(self.paths)["weekly"]["integrity"])

    def test_successful_send_records_weekly(self):
        self.enroll()
        self.post.side_effect = None
        self.post.return_value = (200, {"status": "ok"})
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT_WITH_WEEKLY):
            self.run_cli("run")
        weekly = config.load_state(self.paths)["weekly"]
        self.assertEqual(set(weekly), {"integrity"})
        self.assertRegex(weekly["integrity"]["next_run"], r"^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$")

    def test_run_asks_only_for_due_weekly_sections(self):
        self.enroll()
        self.post.side_effect = None
        self.post.return_value = (200, {"status": "ok"})
        state = config.load_state(self.paths)
        state["weekly"] = {"integrity": {"next_run": "2999-01-01T00:00:00Z"}}
        config.save_state(self.paths, state)
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT) as build:
            self.run_cli("run")
        build.assert_called_once_with(sections_due={"code_patterns"})

    def test_collect_does_not_run_weekly_or_write_state(self):
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT) as build:
            self.run_cli("collect")
        build.assert_called_once_with()
        self.assertFalse(os.path.exists(self.paths.state))

    def test_collect_full_runs_weekly(self):
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT) as build:
            code, _, _ = self.run_cli("collect", "--full")
        self.assertEqual(code, 0)
        build.assert_called_once_with(sections_due=set(sections.WEEKLY))
        self.assertFalse(os.path.exists(self.paths.state))

    def test_status_prints_failed_sections(self):
        self.enroll()
        state = config.load_state(self.paths)
        state["collector_errors"] = [
            {"collector": "sections.packages", "message": "timeout"},
            {"collector": "mysql", "message": "inaccessible"},
        ]
        config.save_state(self.paths, state)
        _, out, _ = self.run_cli("status")
        self.assertIn("Section en panne [packages] : timeout", out)
        self.assertIn("Erreur de collecte [mysql] : inaccessible", out)

    def test_config_save_preserves_weekly_interval(self):
        cfg = config.Config(url="https://c.example", token="t", weekly_interval_days=3)
        config.save(self.paths, cfg)
        self.assertEqual(config.load(self.paths).weekly_interval_days, 3)

    def test_weekly_interval_defaults_to_seven_and_survives_garbage(self):
        config.save(self.paths, config.Config(url="https://c.example", token="t"))
        self.assertEqual(config.load(self.paths).weekly_interval_days, 7)
        with open(self.paths.conf) as fh:
            text = fh.read().replace("weekly_interval_days = 7", "weekly_interval_days = banana")
        with open(self.paths.conf, "w") as fh:
            fh.write(text)
        self.assertEqual(config.load(self.paths).weekly_interval_days, 7)

    def test_reenroll_keeps_the_weekly_interval(self):
        config.save(self.paths, config.Config(url="https://old.example", token="t", weekly_interval_days=3))
        self.enroll()
        self.assertEqual(config.load(self.paths).weekly_interval_days, 3)


WEEKLY_RAN = {
    **SNAPSHOT,
    "sections": {
        name: {"collected_at": "2026-11-01T08:00:00Z", "error": None, "source_errors": {}, "data": [], "sha256": "h"}
        for name in sections.WEEKLY
    },
}
T0 = "2026-11-01T08:00:00Z"
T0_1H = "2026-11-01T09:00:00Z"
T0_25H = "2026-11-02T09:00:00Z"
T0_8D = "2026-11-09T08:00:00Z"


class WeeklyAttemptTest(CliTest):
    """A send that keeps failing must not rerun the slow sections every hour."""

    def run_at(self, when, http_status, snap=WEEKLY_RAN):
        self.post.side_effect = None
        self.post.return_value = (http_status, {"message": "x"})
        with mock.patch.object(cli.util, "now_utc", return_value=when), \
                mock.patch.object(cli.snapshot, "build_snapshot", return_value=snap) as build:
            self.run_cli("run")
        return build.call_args.kwargs["sections_due"]

    def save_config(self):
        config.save(self.paths, config.Config(url="https://c.example", token="t"))

    def test_failed_send_records_the_attempt_only(self):
        self.save_config()
        self.run_at(T0, 500)
        weekly = config.load_state(self.paths)["weekly"]
        self.assertEqual(weekly["integrity"], {"last_attempt": T0})

    def test_failed_send_does_not_recollect_at_one_hour(self):
        self.save_config()
        self.assertEqual(self.run_at(T0, 500), set(sections.WEEKLY))
        self.assertEqual(self.run_at(T0_1H, 500, SNAPSHOT), set())

    def test_failed_send_recollects_after_25_hours(self):
        self.save_config()
        self.run_at(T0, 500)
        self.run_at(T0_1H, 500, SNAPSHOT)
        self.assertEqual(self.run_at(T0_25H, 500), set(sections.WEEKLY))

    def test_transport_failure_counts_as_an_attempt(self):
        self.save_config()
        self.post.side_effect = ssl.SSLError("nope")
        with mock.patch.object(cli.util, "now_utc", return_value=T0), \
                mock.patch.object(cli.snapshot, "build_snapshot", return_value=WEEKLY_RAN):
            self.run_cli("run")
        self.assertEqual(config.load_state(self.paths)["weekly"]["integrity"]["last_attempt"], T0)

    def test_delivered_result_follows_next_run(self):
        self.save_config()
        self.run_at(T0, 200)
        entry = config.load_state(self.paths)["weekly"]["integrity"]
        self.assertEqual(entry["last_attempt"], T0)
        self.assertEqual(entry["next_run"], "2026-11-08T08:00:00Z")
        self.assertEqual(self.run_at(T0_25H, 200, SNAPSHOT), set())
        self.assertEqual(self.run_at(T0_8D, 200), set(sections.WEEKLY))

    def test_failure_after_a_delivery_holds_the_due_section_back_24h(self):
        self.save_config()
        self.run_at(T0, 200)
        self.assertEqual(self.run_at(T0_8D, 500), set(sections.WEEKLY))
        self.assertEqual(self.run_at("2026-11-09T09:00:00Z", 500, SNAPSHOT), set())
        self.assertEqual(self.run_at("2026-11-10T09:00:00Z", 500), set(sections.WEEKLY))

    def test_skipped_sections_get_no_attempt(self):
        self.save_config()
        self.run_at(T0, 500, SNAPSHOT_WITH_WEEKLY)
        self.assertEqual(set(config.load_state(self.paths)["weekly"]), {"integrity"})


class EnrollmentSendTest(CliTest):
    def test_enrollment_runs_no_weekly_section(self):
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT) as build:
            self.enroll()
        build.assert_called_once_with(sections_due=set())

    def test_weekly_sections_stay_due_after_enrollment(self):
        skipped = {**SNAPSHOT, "sections": {n: {"error": "skipped_not_due"} for n in sections.WEEKLY}}
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=skipped):
            self.enroll()
        state = config.load_state(self.paths)
        self.assertNotIn("weekly", state)
        self.assertEqual(sections.due_weekly(state, 7, "2026-11-01T08:00:00Z"), set(sections.WEEKLY))
        self.assertIsNotNone(state["last_success_at"])

    def test_run_after_enrollment_collects_them(self):
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT):
            self.enroll()
        self.post.side_effect = None
        self.post.return_value = (200, {})
        with mock.patch.object(cli.snapshot, "build_snapshot", return_value=SNAPSHOT) as build:
            self.run_cli("run")
        build.assert_called_once_with(sections_due=set(sections.WEEKLY))


class TimeoutPerRequestTest(CliTest):
    def test_inventory_gets_300s_and_enrollment_the_default(self):
        self.enroll()
        enroll_call, inventory_call = self.post.call_args_list
        self.assertNotIn("timeout", enroll_call.kwargs)
        self.assertEqual(inventory_call.kwargs["timeout"], 300)


class SaveStateTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.paths = config.Paths(etc_dir=self.tmp.name, state_dir=os.path.join(self.tmp.name, "state"))

    def test_private_and_leaves_no_temporary_file(self):
        config.save_state(self.paths, {"a": 1})
        self.assertEqual(config.load_state(self.paths), {"a": 1})
        self.assertEqual(stat.S_IMODE(os.stat(self.paths.state).st_mode), 0o600)
        self.assertEqual(os.listdir(self.paths.state_dir), ["state.json"])

    def test_two_runs_at_once_do_not_crash_each_other(self):
        failures = []

        def writer(value):
            try:
                for _ in range(100):
                    config.save_state(self.paths, {"value": value})
            except Exception as exc:
                failures.append(exc)

        threads = [threading.Thread(target=writer, args=(i,)) for i in range(4)]
        for thread in threads:
            thread.start()
        for thread in threads:
            thread.join()
        self.assertEqual(failures, [])
        self.assertIn(config.load_state(self.paths)["value"], range(4))
        self.assertEqual(os.listdir(self.paths.state_dir), ["state.json"])

    def test_a_failed_write_cleans_up_and_keeps_the_old_state(self):
        config.save_state(self.paths, {"a": 1})
        with self.assertRaises(TypeError):
            config.save_state(self.paths, {"a": object()})
        self.assertEqual(config.load_state(self.paths), {"a": 1})
        self.assertEqual(os.listdir(self.paths.state_dir), ["state.json"])


if __name__ == "__main__":
    unittest.main()
