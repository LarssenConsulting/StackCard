import hashlib
import os
import shutil
import subprocess
import tempfile
import textwrap
import unittest

INSTALL_SH = os.path.join(os.path.dirname(__file__), "..", "packaging", "install.sh")
DEB = "discover-agent_latest_all.deb"
BASE = "https://downloads.example.fr/agent"
SHELLS = [s for s in ("sh", "dash") if shutil.which(s)]

# Fake system commands: each one appends what it was asked to do to $STUB_LOG.
STUBS = {
    "id": 'echo "${STUB_UID:-0}"',
    "stty": "exit 0",
    "curl": """
        out=""; url=""
        while [ $# -gt 0 ]; do
            case "$1" in -o) out=$2; shift ;; -*) ;; *) url=$1 ;; esac
            shift
        done
        case "$url" in
            */up)
                echo "check $url" >> "$STUB_LOG"
                case "$url" in *"${STUB_DOWN_HOST:-<none>}"*) exit 7 ;; esac
                exit 0 ;;
        esac
        echo "download $url" >> "$STUB_LOG"
        [ -f "$STUB_FILES/${url##*/}" ] || exit 22
        cp "$STUB_FILES/${url##*/}" "$out"
    """,
    "dpkg-query": '[ -n "${STUB_INSTALLED:-}" ] || exit 1; printf "installed:%s" "$STUB_INSTALLED"',
    "dpkg-deb": 'echo "$STUB_AVAILABLE"',
    "dpkg": """
        [ "$1" = "--compare-versions" ] || exit 2
        python3 -c 'import sys; v=lambda s: tuple(map(int, s.split("."))); sys.exit(0 if v(sys.argv[1]) > v(sys.argv[2]) else 1)' "$2" "$4"
    """,
    "apt-get": 'echo "apt-get $*" >> "$STUB_LOG"',
    "discover-agent": """
        case "$1" in
            status)
                if [ -n "${STUB_ENROLLED_URL:-}" ]; then
                    printf 'Enrôlé : oui\\nCentral : %s\\n' "$STUB_ENROLLED_URL"
                else
                    echo "Enrôlé : non"
                fi ;;
            enroll)
                IFS= read -r token
                echo "enroll $* stdin=$token" >> "$STUB_LOG"
                n=$(cat "$STUB_LOG.enroll" 2>/dev/null || echo 0); n=$((n + 1)); echo "$n" > "$STUB_LOG.enroll"
                [ "$n" -gt "${STUB_ENROLL_FAILURES:-0}" ] || { echo "HTTP 410" >&2; exit 1; }
                exit "${STUB_ENROLL_EXIT:-0}" ;;
        esac
    """,
}


class InstallScriptTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        root = self.tmp.name
        self.bin = os.path.join(root, "bin")
        self.files = os.path.join(root, "files")
        os.makedirs(self.bin)
        os.makedirs(self.files)
        for name, body in STUBS.items():
            path = os.path.join(self.bin, name)
            with open(path, "w") as fh:
                fh.write("#!/bin/sh\n" + textwrap.dedent(body))
            os.chmod(path, 0o755)
        self.log = os.path.join(root, "log")
        self.publish(b"deb-bytes")

    def publish(self, deb, checksum=None):
        with open(os.path.join(self.files, DEB), "wb") as fh:
            fh.write(deb)
        digest = checksum or hashlib.sha256(deb).hexdigest()
        with open(os.path.join(self.files, DEB + ".sha256"), "w") as fh:
            fh.write(f"{digest}  {DEB}\n")

    def run_install(self, shell, answers=None, **env):
        tty = os.path.join(self.tmp.name, "tty")
        if answers is None:
            tty = os.path.join(self.tmp.name, "no-tty")
        else:
            with open(tty, "w") as fh:
                fh.write("".join(a + "\n" for a in answers))
        if os.path.exists(self.log):
            os.remove(self.log)
        if os.path.exists(self.log + ".enroll"):
            os.remove(self.log + ".enroll")
        full_env = {
            "PATH": self.bin + os.pathsep + "/usr/bin:/bin:/usr/sbin:/sbin",
            "HOME": self.tmp.name,
            "STUB_LOG": self.log,
            "STUB_FILES": self.files,
            "STUB_AVAILABLE": "0.2.0",
            "DISCOVER_DOWNLOAD_BASE": BASE,
            "DISCOVER_TTY": tty,
            **env,
        }
        proc = subprocess.run(
            [shell, INSTALL_SH], env=full_env, stdin=subprocess.DEVNULL,
            capture_output=True, text=True, timeout=30,
        )
        calls = open(self.log).read().splitlines() if os.path.exists(self.log) else []
        return proc, calls

    def enrolls(self, calls):
        return [c for c in calls if c.startswith("enroll ")]

    def for_each_shell(self, test):
        for shell in SHELLS:
            with self.subTest(shell=shell):
                test(shell)

    def test_fresh_install_downloads_installs_and_enrolls(self):
        def check(shell):
            proc, calls = self.run_install(shell, ["sentinel.example.fr", "tok-123"])
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertIn(f"download {BASE}/{DEB}", calls)
            self.assertTrue(any(c.startswith("apt-get install -y ") and c.endswith(DEB) for c in calls), calls)
            self.assertIn("check https://sentinel.example.fr/up", calls)
            self.assertEqual(
                self.enrolls(calls),
                ["enroll enroll --url https://sentinel.example.fr --token - stdin=tok-123"],
            )
            self.assertNotIn("tok-123", proc.stderr + proc.stdout)
        self.for_each_shell(check)

    def test_accepts_an_url_instead_of_a_bare_fqdn(self):
        def check(shell):
            proc, calls = self.run_install(shell, ["https://sentinel.example.fr/", "tok"])
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertIn("--url https://sentinel.example.fr --token -", self.enrolls(calls)[0])
        self.for_each_shell(check)

    def test_unreachable_central_asks_again_before_using_the_token(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, ["typo.example.fr", "sentinel.example.fr", "tok"], STUB_DOWN_HOST="typo.example.fr",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertIn("check https://typo.example.fr/up", calls)
            self.assertEqual(len(self.enrolls(calls)), 1)
            self.assertIn("https://sentinel.example.fr", self.enrolls(calls)[0])
            self.assertIn("typo.example.fr", proc.stderr)
        self.for_each_shell(check)

    def test_rejected_token_is_asked_again(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, ["sentinel.example.fr", "bad", "good"], STUB_ENROLL_FAILURES="1",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual([c.rsplit("=", 1)[1] for c in self.enrolls(calls)], ["bad", "good"])
        self.for_each_shell(check)

    def test_enrolled_but_first_inventory_failed_does_not_ask_for_a_new_token(self):
        def check(shell):
            proc, calls = self.run_install(shell, ["sentinel.example.fr", "tok"], STUB_ENROLL_EXIT="3")
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(len(self.enrolls(calls)), 1)
            self.assertIn("inventaire", proc.stderr)
        self.for_each_shell(check)

    def test_unattended_with_environment_variables(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, None, DISCOVER_URL="sentinel.example.fr", DISCOVER_TOKEN="tok-env",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(
                self.enrolls(calls),
                ["enroll enroll --url https://sentinel.example.fr --token - stdin=tok-env"],
            )
        self.for_each_shell(check)

    def test_unattended_failure_does_not_loop(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, None, DISCOVER_URL="sentinel.example.fr", DISCOVER_TOKEN="bad", STUB_ENROLL_FAILURES="5",
            )
            self.assertNotEqual(proc.returncode, 0)
            self.assertEqual(len(self.enrolls(calls)), 1)
        self.for_each_shell(check)

    def test_fresh_install_without_terminal_or_variables_explains_what_to_do(self):
        def check(shell):
            proc, calls = self.run_install(shell, None)
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn("DISCOVER_URL", proc.stderr)
            self.assertEqual(self.enrolls(calls), [])
        self.for_each_shell(check)

    def test_rerun_up_to_date_and_keep_token(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, ["n"], STUB_INSTALLED="0.2.0", STUB_ENROLLED_URL="https://sentinel.example.fr",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertFalse(any(c.startswith("apt-get") for c in calls), calls)
            self.assertEqual(self.enrolls(calls), [])
            self.assertIn("Mettre à jour le jeton", proc.stderr)
        self.for_each_shell(check)

    def test_rerun_upgrades_and_updates_token_with_current_central_as_default(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, ["o", "", "tok-new"],
                STUB_INSTALLED="0.1.0", STUB_ENROLLED_URL="https://sentinel.example.fr",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertTrue(any(c.startswith("apt-get install -y ") for c in calls), calls)
            self.assertEqual(
                self.enrolls(calls),
                ["enroll enroll --url https://sentinel.example.fr --token - stdin=tok-new"],
            )
        self.for_each_shell(check)

    def test_rerun_without_terminal_keeps_enrollment(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, None, STUB_INSTALLED="0.2.0", STUB_ENROLLED_URL="https://sentinel.example.fr",
            )
            self.assertEqual(proc.returncode, 0, proc.stderr)
            self.assertEqual(self.enrolls(calls), [])
        self.for_each_shell(check)

    def test_bad_checksum_installs_nothing(self):
        def check(shell):
            self.publish(b"deb-bytes", checksum="0" * 64)
            proc, calls = self.run_install(shell, ["sentinel.example.fr", "tok"])
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn("SHA-256", proc.stderr)
            self.assertFalse(any(c.startswith("apt-get") for c in calls), calls)
        self.for_each_shell(check)

    def test_requires_root(self):
        def check(shell):
            proc, calls = self.run_install(shell, ["sentinel.example.fr", "tok"], STUB_UID="1000")
            self.assertNotEqual(proc.returncode, 0)
            self.assertIn("root", proc.stderr)
            self.assertEqual(calls, [])
        self.for_each_shell(check)

    def test_refuses_a_non_https_download_base(self):
        def check(shell):
            proc, calls = self.run_install(
                shell, ["sentinel.example.fr", "tok"], DISCOVER_DOWNLOAD_BASE="http://downloads.example.fr/agent",
            )
            self.assertNotEqual(proc.returncode, 0)
            self.assertEqual(calls, [])
        self.for_each_shell(check)


if __name__ == "__main__":
    unittest.main()
