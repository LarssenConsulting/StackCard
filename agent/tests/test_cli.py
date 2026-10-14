import contextlib
import io
import json
import os
import ssl
import stat
import tempfile
import unittest
from unittest import mock

from discover_agent import cli, config

SNAPSHOT = {"schema_version": 1, "server": {"hostname": "web01"}, "errors": []}
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


if __name__ == "__main__":
    unittest.main()
