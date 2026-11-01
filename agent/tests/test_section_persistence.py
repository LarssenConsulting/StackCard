import base64
import hashlib
import inspect
import json
import struct
import unittest

from discover_agent import util
from discover_agent.sections import REGISTRY, persistence

DPKG = "/var/lib/dpkg/info"
ETC_UNITS = "/etc/systemd/system"
LIB_UNITS = "/lib/systemd/system"
FORBIDDEN_NAMES = (".credentials", ".credentials_rsaparams", ".runner")

# Generated with ssh-keygen; the fingerprint is what `ssh-keygen -lf` printed.
REAL_KEY = "AAAAC3NzaC1lZDI1NTE5AAAAIDWXOs62x5baiB1BNINjeXPBpadhIczTy3w+da050FhN"
REAL_FINGERPRINT = "SHA256:n0Y2J3RmZ1kiESCZySxZlHPHKuYwRS+375H7bdnxEAw"
PASTED_KEY = "AAAAC3NzaC1lZDI1NTE5AAAAIPASTEDPASTEDPASTEDPASTEDPASTEDPASTEDPAST"


def key_blob(key_type="ssh-rsa", payload=b"raw-key-material"):
    name = key_type.encode()
    return base64.b64encode(struct.pack(">I", len(name)) + name + payload).decode()


class Host:
    """Fake file system: every path handed to a reader is recorded."""

    def __init__(self, files=None, dirs=None, lists=None, links=(), unreadable=()):
        self.files = {"/etc/passwd": "", **(files or {})}
        self.dirs = {DPKG: ["base.list"], **(dirs or {})}
        self.lists = {f"{DPKG}/base.list": "/usr/bin/x\n", **(lists or {})}
        self.links = set(links)
        self.unreadable = set(unreadable)
        self.read = []
        self.list_read = []

    def reader(self, path):
        self.read.append(path)
        return self.files.get(path)

    def list_reader(self, path):
        self.list_read.append(path)
        return self.lists.get(path)

    def listdir(self, path):
        found = self.dirs.get(path)
        if found is None:
            raise FileNotFoundError(2, "No such file or directory", path)
        if isinstance(found, Exception):
            raise found
        return list(found)

    def exists(self, path):
        return path in self.files or path in self.unreadable

    def collect(self):
        return persistence.collect(
            reader=self.reader,
            list_reader=self.list_reader,
            listdir=self.listdir,
            islink=self.links.__contains__,
            exists=self.exists,
        )


class CronTest(unittest.TestCase):
    def test_cron_active_lines_redacted(self):
        host = Host(
            files={
                "/etc/crontab": "# system\n\nSHELL=/bin/sh\n17 * * * * root run-parts /etc/cron.hourly\n",
                "/etc/cron.d/backup": "  # nightly\nMAILTO=admin@example.com\n0 3 * * * root mysqldump -pS3cret db\n",
                "/var/spool/cron/crontabs/alice": "PGPASSWORD=hunter2 pg_dump db\n# no\n",
            },
            dirs={"/etc/cron.d": ["backup"], "/var/spool/cron/crontabs": ["alice"]},
        )
        crons = host.collect().data["crons"]
        self.assertEqual(
            crons,
            [
                {"file": "/etc/cron.d/backup", "user": "root", "ignored": False, "line": "0 3 * * * root mysqldump -p*** db"},
                {"file": "/etc/cron.d/backup", "user": None, "ignored": False, "line": "MAILTO=admin@example.com"},
                {"file": "/etc/crontab", "user": "root", "ignored": False, "line": "17 * * * * root run-parts /etc/cron.hourly"},
                {"file": "/etc/crontab", "user": None, "ignored": False, "line": "SHELL=/bin/sh"},
                {"file": "/var/spool/cron/crontabs/alice", "user": "alice", "ignored": False, "line": "PGPASSWORD=*** pg_dump db"},
            ],
        )

    def test_user_of_system_crons(self):
        def user(line):
            return Host(files={"/etc/crontab": line + "\n"}).collect().data["crons"][0]["user"]

        self.assertEqual(user("0 3 * * * www-data /bin/x"), "www-data")
        self.assertEqual(user("@reboot deploy /bin/x"), "deploy")
        self.assertEqual(user("@daily root /bin/x"), "root")
        self.assertIsNone(user("MAILTO=admin@example.com"))
        self.assertIsNone(user('"PATH" = /bin'))
        self.assertIsNone(user("too short"))

    def test_user_field_never_carries_a_secret(self):
        result = Host(files={"/etc/crontab": "@daily PASSWORD=S3cret /bin/x\n"}).collect()
        self.assertIsNone(result.data["crons"][0]["user"])
        self.assertNotIn("S3cret", json.dumps(result.data))

    def test_cron_d_files_cron_skips_are_marked(self):
        host = Host(
            files={f"/etc/cron.d/{n}": "* * * * * root /bin/x\n" for n in ("a.dpkg-old", "e2scrub_all", "b~")},
            dirs={"/etc/cron.d": ["a.dpkg-old", "e2scrub_all", "b~"]},
        )
        self.assertEqual(
            {r["file"]: r["ignored"] for r in host.collect().data["crons"]},
            {"/etc/cron.d/a.dpkg-old": True, "/etc/cron.d/e2scrub_all": False, "/etc/cron.d/b~": True},
        )

    def test_only_file_refused_is_null_not_empty(self):
        host = Host(dirs={"/etc/cron.d": ["fifo"]}, unreadable={"/etc/cron.d/fifo"})
        result = host.collect()
        self.assertIsNone(result.data["crons"])
        self.assertIn("crons:/etc/cron.d/fifo", result.source_errors)

    def test_exotic_line_separators_do_not_split_a_secret_out_of_its_key(self):
        host = Host(files={"/etc/crontab": "DB_PASSWORD=\x0cS3cret\n"})
        self.assertNotIn("S3cret", json.dumps(host.collect().data))

    def test_absent_sources_are_empty(self):
        result = Host().collect()
        self.assertEqual(result.data["crons"], [])
        self.assertEqual(result.source_errors, {})

    def test_unreadable_file_is_reported_and_the_rest_kept(self):
        host = Host(
            files={"/etc/cron.d/ok": "* * * * * root /bin/ok\n"},
            dirs={"/etc/cron.d": ["fifo", "ok"]},
            unreadable={"/etc/cron.d/fifo"},
        )
        result = host.collect()
        self.assertEqual([c["file"] for c in result.data["crons"]], ["/etc/cron.d/ok"])
        self.assertIn("crons:/etc/cron.d/fifo", result.source_errors)

    def test_one_unreadable_directory_keeps_the_others(self):
        host = Host(
            files={"/etc/crontab": "* * * * * root /bin/ok\n"},
            dirs={"/etc/cron.d": PermissionError(13, "denied")},
        )
        result = host.collect()
        self.assertEqual(len(result.data["crons"]), 1)
        self.assertIn("crons:/etc/cron.d", result.source_errors)

    def test_nothing_readable_is_null(self):
        denied = PermissionError(13, "denied")
        host = Host(
            dirs={"/etc/cron.d": denied, "/var/spool/cron/crontabs": denied},
            unreadable={"/etc/crontab"},
        )
        result = host.collect()
        self.assertIsNone(result.data["crons"])
        self.assertIn("crons", result.source_errors)
        self.assertEqual([k for k in result.source_errors if k.startswith("crons:")], [])


class SudoersTest(unittest.TestCase):
    def sudoers(self, text, name="deploy"):
        host = Host(files={f"/etc/sudoers.d/{name}": text}, dirs={"/etc/sudoers.d": [name]})
        return host.collect().data["sudoers"]

    def test_sudoers_active_lines(self):
        rows = self.sudoers(
            "# comment\n\nDefaults:deploy !requiretty\ndeploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl\n"
        )
        self.assertEqual(
            [r["line"] for r in rows],
            ["Defaults:deploy !requiretty", "deploy ALL=(ALL) NOPASSWD: /usr/bin/systemctl"],
        )
        self.assertEqual(rows[0]["file"], "/etc/sudoers.d/deploy")
        self.assertFalse(rows[0]["ignored"])

    def test_include_directives_are_kept(self):
        rows = self.sudoers("#includedir /opt/sudo\n#include /opt/one\n#included by ansible\n@include /opt/two\n")
        self.assertEqual(
            [r["line"] for r in rows], ["#include /opt/one", "#includedir /opt/sudo", "@include /opt/two"]
        )

    def test_numeric_user_id_is_not_a_comment(self):
        rows = self.sudoers(
            "#1000 ALL=(ALL) NOPASSWD: ALL\n#1001,alice ALL=(ALL) NOPASSWD: ALL\n#-1 ALL=ALL\n#not this\n# 1000 nor this\n"
        )
        self.assertEqual(
            [r["line"] for r in rows],
            ["#-1 ALL=ALL", "#1000 ALL=(ALL) NOPASSWD: ALL", "#1001,alice ALL=(ALL) NOPASSWD: ALL"],
        )

    def test_files_sudo_skips_are_kept_and_marked(self):
        host = Host(
            files={f"/etc/sudoers.d/{n}": "evil ALL=(ALL) ALL\n" for n in ("backup.bak", "old~", "real")},
            dirs={"/etc/sudoers.d": ["old~", "real", "backup.bak"]},
        )
        rows = host.collect().data["sudoers"]
        self.assertEqual(
            {r["file"]: r["ignored"] for r in rows},
            {"/etc/sudoers.d/backup.bak": True, "/etc/sudoers.d/old~": True, "/etc/sudoers.d/real": False},
        )

    def test_tag_and_alias_names_survive_masking(self):
        lines = [
            "deploy ALL=(ALL) PASSWD: /bin/ls, /bin/cat",
            "deploy ALL=(ALL) NOPASSWD: /bin/ls, PASSWD: /bin/rm",
            "Cmnd_Alias PASSWD_CMDS = /usr/bin/passwd, /usr/sbin/chpasswd",
            "User_Alias KEYHOLDERS = alice, bob",
        ]
        rows = self.sudoers("\n".join(lines) + "\n")
        self.assertEqual(sorted(r["line"] for r in rows), sorted(lines))

    def test_values_are_still_masked(self):
        rows = self.sudoers("Defaults password=S3cret\n")
        self.assertNotIn("S3cret", rows[0]["line"])

    def test_only_file_refused_is_null_not_empty(self):
        host = Host(dirs={"/etc/sudoers.d": ["evil"]}, unreadable={"/etc/sudoers.d/evil"})
        result = host.collect()
        self.assertIsNone(result.data["sudoers"])
        self.assertIn("sudoers:/etc/sudoers.d/evil", result.source_errors)

    def test_continued_line_is_joined_before_redaction(self):
        rows = self.sudoers('Defaults mailerflags="password=\\\nS3cret"\n')
        self.assertEqual(len(rows), 1)
        self.assertNotIn("S3cret", rows[0]["line"])

    def test_unreadable_directory_is_null(self):
        result = Host(dirs={"/etc/sudoers.d": PermissionError(13, "denied")}).collect()
        self.assertIsNone(result.data["sudoers"])
        self.assertIn("sudoers", result.source_errors)

    def test_absent_directory_is_empty(self):
        self.assertEqual(Host().collect().data["sudoers"], [])


class UnitsTest(unittest.TestCase):
    def test_orphan_units(self):
        host = Host(
            files={
                f"{ETC_UNITS}/evil.service": "[Service]\nExecStart=/opt/evil --password=S3cret\n",
                f"{LIB_UNITS}/owned.service": "[Service]\nExecStart=/usr/bin/owned\n",
            },
            dirs={
                ETC_UNITS: ["evil.service", "linked.service", "multi-user.target.wants", "notes.txt"],
                LIB_UNITS: ["owned.service"],
                DPKG: ["base.list", "other:amd64.list", "not-a-list.md5sums"],
            },
            lists={
                f"{DPKG}/base.list": f"/usr/bin/x\n{LIB_UNITS}/owned.service\n",
                f"{DPKG}/other:amd64.list": "/etc/other\n",
            },
            links={f"{ETC_UNITS}/linked.service"},
        )
        result = host.collect()
        self.assertEqual(
            result.data["units"],
            [
                {
                    "name": "evil.service",
                    "path": f"{ETC_UNITS}/evil.service",
                    "exec_start": ["/opt/evil --password=***"],
                    "drop_in": False,
                }
            ],
        )
        self.assertEqual(sorted(host.list_read), [f"{DPKG}/base.list", f"{DPKG}/other:amd64.list"])
        self.assertNotIn(f"{ETC_UNITS}/linked.service", host.read)
        self.assertEqual(result.source_errors, {})

    def test_all_unit_types_are_scanned(self):
        names = ["a.service", "b.timer", "c.socket", "d.path", "e.mount", "f.target", "g.txt", "h.wants"]
        host = Host(files={f"{LIB_UNITS}/{n}": "" for n in names}, dirs={LIB_UNITS: names})
        self.assertEqual([u["name"] for u in host.collect().data["units"]], names[:6])

    def test_unowned_drop_in(self):
        conf = f"{ETC_UNITS}/ssh.service.d/override.conf"
        owned = f"{LIB_UNITS}/ssh.service.d/packaged.conf"
        linked = f"{ETC_UNITS}/ssh.service.d/linked.conf"
        host = Host(
            files={
                conf: "[Service]\nExecStart=\nExecStart=/opt/evil --token=S3cret\n",
                owned: "[Service]\nExecStart=/bin/owned\n",
            },
            dirs={
                ETC_UNITS: ["ssh.service.d", "linked.service.d"],
                LIB_UNITS: ["ssh.service.d"],
                f"{ETC_UNITS}/ssh.service.d": ["linked.conf", "notes.txt", "override.conf"],
                f"{LIB_UNITS}/ssh.service.d": ["packaged.conf"],
            },
            lists={f"{DPKG}/base.list": "/usr/lib/systemd/system/ssh.service.d/packaged.conf\n"},
            links={linked, f"{ETC_UNITS}/linked.service.d"},
        )
        result = host.collect()
        self.assertEqual(
            result.data["units"],
            [
                {
                    "name": "ssh.service.d/override.conf",
                    "path": conf,
                    "exec_start": ["/opt/evil --token=***"],
                    "drop_in": True,
                }
            ],
        )
        self.assertEqual(result.source_errors, {})

    def test_drop_in_directory_failures(self):
        host = Host(
            dirs={
                ETC_UNITS: ["a.service.d", "plainfile.d", "ok.service"],
                f"{ETC_UNITS}/a.service.d": PermissionError(13, "denied"),
                f"{ETC_UNITS}/plainfile.d": NotADirectoryError(20, "not a directory"),
            },
            files={f"{ETC_UNITS}/ok.service": ""},
        )
        result = host.collect()
        self.assertEqual([u["name"] for u in result.data["units"]], ["ok.service"])
        self.assertEqual(list(result.source_errors), [f"units:{ETC_UNITS}/a.service.d"])

    def test_merged_usr_paths_are_the_same_unit(self):
        # noble packages list /usr/lib/systemd/system; /lib is a symlink to /usr/lib.
        host = Host(
            files={f"{LIB_UNITS}/owned.service": "[Service]\nExecStart=/usr/bin/owned\n"},
            dirs={LIB_UNITS: ["owned.service"]},
            lists={f"{DPKG}/base.list": "/usr/lib/systemd/system/owned.service\n"},
        )
        self.assertEqual(host.collect().data["units"], [])

    def test_timer_has_no_exec_start(self):
        host = Host(files={f"{ETC_UNITS}/t.timer": "[Timer]\nOnCalendar=daily\n"}, dirs={ETC_UNITS: ["t.timer"]})
        self.assertEqual(host.collect().data["units"][0]["exec_start"], [])

    def test_continuation_joined_before_redaction(self):
        unit = (
            "[Service]\n"
            "ExecStartPre=/bin/not-this\n"
            "ExecStart=/usr/bin/mysql -u x \\\n"
            "  # a comment inside the continuation\n"
            "  -pS3cret db\n"
        )
        host = Host(files={f"{ETC_UNITS}/a.service": unit}, dirs={ETC_UNITS: ["a.service"]})
        self.assertEqual(host.collect().data["units"][0]["exec_start"], ["/usr/bin/mysql -u x -p*** db"])

    def test_empty_exec_start_resets_the_list(self):
        unit = "[Service]\nExecStart=/bin/old\nExecStart=\nExecStart = /bin/one\nExecStart=/bin/two\n"
        host = Host(files={f"{ETC_UNITS}/a.service": unit}, dirs={ETC_UNITS: ["a.service"]})
        self.assertEqual(host.collect().data["units"][0]["exec_start"], ["/bin/one", "/bin/two"])

    def test_unreadable_unit_stays_listed(self):
        host = Host(dirs={ETC_UNITS: ["a.service"]}, unreadable={f"{ETC_UNITS}/a.service"})
        result = host.collect()
        self.assertIsNone(result.data["units"][0]["exec_start"])
        self.assertIn(f"units:{ETC_UNITS}/a.service", result.source_errors)

    def test_dpkg_database_missing_is_not_every_unit_orphan(self):
        host = Host(files={f"{ETC_UNITS}/a.service": ""}, dirs={ETC_UNITS: ["a.service"], DPKG: []})
        result = host.collect()
        self.assertIsNone(result.data["units"])
        self.assertIn("units", result.source_errors)

    def test_unreadable_dpkg_list_is_not_a_false_orphan(self):
        host = Host(
            files={f"{ETC_UNITS}/a.service": ""},
            dirs={ETC_UNITS: ["a.service"], DPKG: ["base.list", "lost.list"]},
        )
        result = host.collect()
        self.assertIsNone(result.data["units"])
        self.assertIn("units", result.source_errors)

    def test_dpkg_lists_use_a_reader_without_the_user_file_limit(self):
        # linux-headers .list files exceed the 1 MiB limit of read_regular_file.
        default = inspect.signature(persistence.collect).parameters["list_reader"].default
        self.assertIs(default, util.read_text)


class UsersTest(unittest.TestCase):
    PASSWD = (
        "root:x:0:0:root:/root:/bin/bash\n"
        "daemon:x:1:1:daemon:/usr/sbin:/usr/sbin/nologin\n"
        "ftp:x:2:2::/srv/ftp:/bin/false\n"
        "old:$6$salthash:1001:1001:Some Gecos:/home/old:/bin/sh\n"
        "broken line\n"
        "bad:x:notanint:1::/home/bad:/bin/sh\n"
    )

    def test_login_users(self):
        host = Host(files={"/etc/passwd": self.PASSWD})
        result = host.collect()
        self.assertEqual(
            result.data["users"],
            [
                {"name": "old", "uid": 1001, "shell": "/bin/sh", "home": "/home/old"},
                {"name": "root", "uid": 0, "shell": "/bin/bash", "home": "/root"},
            ],
        )
        self.assertNotIn("/etc/shadow", host.read)
        serialized = json.dumps(result.data)
        self.assertNotIn("$6$", serialized)
        self.assertNotIn("Gecos", serialized)

    def test_utility_shells_are_not_login_users(self):
        passwd = (
            "sync:x:4:65534:sync:/bin:/bin/sync\n"
            "shutdown:x:6:0:shutdown:/sbin:/sbin/shutdown\n"
            "halt:x:7:0:halt:/sbin:/sbin/halt\n"
            "alice:x:1000:1000::/home/alice:/usr/bin/zsh\n"
        )
        users = Host(files={"/etc/passwd": passwd}).collect().data["users"]
        self.assertEqual([u["name"] for u in users], ["alice"])

    def test_unreadable_passwd(self):
        host = Host()
        del host.files["/etc/passwd"]
        result = host.collect()
        self.assertIsNone(result.data["users"])
        self.assertIsNone(result.data["authorized_keys"])
        self.assertIn("users", result.source_errors)
        self.assertIn("authorized_keys", result.source_errors)


class AuthorizedKeysTest(unittest.TestCase):
    def keys(self, text, shell="/bin/bash", extra=None):
        files = {
            "/etc/passwd": f"alice:x:1000:1000::/home/alice:{shell}\n",
            "/home/alice/.ssh/authorized_keys": text,
            **(extra or {}),
        }
        host = Host(files=files)
        return host, host.collect()

    def test_authorized_keys_fingerprint_only(self):
        blob = key_blob("ssh-ed25519")
        expected = "SHA256:" + base64.b64encode(hashlib.sha256(base64.b64decode(blob)).digest()).decode().rstrip("=")
        hostile = 'command="echo \\"ssh-rsa AAAA\\" /opt/backdoor",no-pty ssh-ed25519 %s alice@laptop\n' % blob
        host, result = self.keys("# comment\n\n" + hostile + "not a key\nssh-rsa not*base64 x\nssh-rsa\n")
        self.assertEqual(
            result.data["authorized_keys"],
            [
                {
                    "user": "alice",
                    "file": "/home/alice/.ssh/authorized_keys",
                    "type": "ssh-ed25519",
                    "fingerprint": expected,
                    "certificate": False,
                    "comment": "alice@laptop",
                }
            ],
        )
        serialized = json.dumps(result.data)
        for raw in (blob, "backdoor", "command", "no-pty"):
            self.assertNotIn(raw, serialized)
        self.assertEqual(host.list_read, [f"{DPKG}/base.list"])

    def test_only_escaped_quote_is_skipped_in_options(self):
        # OpenSSH reads `\\` as two plain characters, then `\"`: this line is a valid key for sshd.
        blob = key_blob("ssh-ed25519")
        _, result = self.keys('command="a\\\\" b" ssh-ed25519 %s evade\n' % blob)
        self.assertEqual([k["comment"] for k in result.data["authorized_keys"]], ["evade"])

    def test_pasted_second_key_does_not_reach_the_comment(self):
        second = key_blob("ssh-ed25519", b"x" * 40)
        _, result = self.keys(f"ssh-rsa {key_blob()} ssh-ed25519 {second} bob@host\nssh-rsa {key_blob('ssh-rsa', b'y')} ssh-rsa\n")
        self.assertCountEqual([k["comment"] for k in result.data["authorized_keys"]], ["bob@host", None])
        self.assertNotIn(second, json.dumps(result.data))

    def test_certificate_has_no_fingerprint(self):
        kind = "ssh-ed25519-cert-v01@openssh.com"
        text = f"{kind} {key_blob(kind, b'a')} a\n{kind} {key_blob(kind, b'b')} b\nssh-rsa {key_blob()} c\n"
        _, result = self.keys(text)
        rows = {k["comment"]: k for k in result.data["authorized_keys"]}
        self.assertEqual((rows["a"]["fingerprint"], rows["a"]["certificate"]), (None, True))
        self.assertEqual((rows["b"]["fingerprint"], rows["b"]["certificate"]), (None, True))
        self.assertFalse(rows["c"]["certificate"])
        self.assertTrue(rows["c"]["fingerprint"].startswith("SHA256:"))

    def test_symlink_to_another_file_gives_no_row(self):
        # read_regular_file follows links: a link to /etc/shadow only yields non-key lines.
        _, result = self.keys("root:$6$salt$HASHVALUE:19000:0:99999:7:::\n")
        self.assertEqual(result.data["authorized_keys"], [])
        self.assertNotIn("HASHVALUE", json.dumps(result.data))

    def test_matches_ssh_keygen(self):
        _, result = self.keys(f"ssh-ed25519 {REAL_KEY} vector@test\n")
        self.assertEqual(result.data["authorized_keys"][0]["fingerprint"], REAL_FINGERPRINT)

    def test_comment_redacted_and_optional(self):
        _, result = self.keys(
            f"ssh-rsa {key_blob('ssh-rsa', b'a')} token=S3cret\nssh-rsa {key_blob('ssh-rsa', b'b')}\n"
        )
        comments = [k["comment"] for k in result.data["authorized_keys"]]
        self.assertCountEqual(comments, ["token=***", None])

    def test_blob_of_another_type_or_not_a_key_is_malformed(self):
        fake = base64.b64encode(b"just some text").decode()
        _, result = self.keys(f"ssh-rsa {key_blob('ssh-ed25519')}\nssh-rsa {fake}\nssh-rsa ==\n")
        self.assertEqual(result.data["authorized_keys"], [])
        self.assertEqual(result.source_errors, {})

    def test_authorized_keys2_is_read(self):
        extra = {"/home/alice/.ssh/authorized_keys2": f"ssh-rsa {key_blob()}\n"}
        _, result = self.keys("", extra=extra)
        self.assertEqual([k["file"] for k in result.data["authorized_keys"]], ["/home/alice/.ssh/authorized_keys2"])

    def test_keys_of_a_nologin_account_are_still_reported(self):
        # sshd still allows port forwarding for such an account.
        _, result = self.keys(f"ssh-rsa {key_blob()}\n", shell="/usr/sbin/nologin")
        self.assertEqual(result.data["users"], [])
        self.assertEqual(len(result.data["authorized_keys"]), 1)

    def test_missing_file_is_empty(self):
        host = Host(files={"/etc/passwd": "alice:x:1000:1000::/home/alice:/bin/bash\n"})
        result = host.collect()
        self.assertEqual(result.data["authorized_keys"], [])
        self.assertEqual(result.source_errors, {})

    def test_unreadable_file_is_reported(self):
        host = Host(
            files={"/etc/passwd": "alice:x:1000:1000::/home/alice:/bin/bash\n"},
            unreadable={"/home/alice/.ssh/authorized_keys"},
        )
        result = host.collect()
        self.assertIsNone(result.data["authorized_keys"])
        self.assertIn("authorized_keys:/home/alice/.ssh/authorized_keys", result.source_errors)

    def test_unreadable_file_keeps_the_rows_of_the_others(self):
        host = Host(
            files={
                "/etc/passwd": "alice:x:1000:1000::/home/alice:/bin/bash\nbob:x:1001:1001::/home/bob:/bin/bash\n",
                "/home/bob/.ssh/authorized_keys": f"ssh-rsa {key_blob()}\n",
            },
            unreadable={"/home/alice/.ssh/authorized_keys"},
        )
        result = host.collect()
        self.assertEqual([k["user"] for k in result.data["authorized_keys"]], ["bob"])
        self.assertIn("authorized_keys:/home/alice/.ssh/authorized_keys", result.source_errors)

    def test_home_of_root_directory_has_a_clean_path(self):
        host = Host(files={"/etc/passwd": "weird:x:5:5::/:/bin/sh\n"})
        host.collect()
        self.assertIn("/.ssh/authorized_keys", host.read)

    def test_user_files_default_to_the_safe_reader(self):
        default = inspect.signature(persistence.collect).parameters["reader"].default
        self.assertIs(default, util.read_regular_file)


class GithubRunnerTest(unittest.TestCase):
    NAME = "actions.runner.org-repo.host.service"
    DIR = "/home/gh/actions-runner"
    DEPS = f"{DIR}/bin/Runner.Listener.deps.json"

    def host(self, **files):
        return Host(
            files={f"{ETC_UNITS}/{self.NAME}": f"[Service]\nExecStart={self.DIR}/runsvc.sh\n", **files},
            dirs={ETC_UNITS: [self.NAME]},
        )

    def test_github_runner(self):
        host = self.host()
        host.files[self.DEPS] = '{"targets": {"x": {"Runner.Common/1.0.0": {}, "Runner.Listener/2.319.1": {}}}}'
        result = host.collect()
        self.assertEqual(
            result.data["github_runners"],
            [{"service": self.NAME, "directory": self.DIR, "version": "2.319.1"}],
        )
        self.assertEqual(result.source_errors, {})
        for path in host.read:
            self.assertNotIn(path.rsplit("/", 1)[-1], FORBIDDEN_NAMES)

    def test_version_none_when_not_found(self):
        result = self.host().collect()
        self.assertIsNone(result.data["github_runners"][0]["version"])
        self.assertEqual(result.source_errors, {})

    def test_directory_none_when_exec_start_is_not_absolute(self):
        host = Host(
            files={f"{ETC_UNITS}/{self.NAME}": "[Service]\nExecStart=runsvc.sh\n"},
            dirs={ETC_UNITS: [self.NAME]},
        )
        runner = host.collect().data["github_runners"][0]
        self.assertIsNone(runner["directory"])
        self.assertIsNone(runner["version"])

    def test_prefixes_and_quotes_of_exec_start(self):
        host = Host(
            files={f"{ETC_UNITS}/{self.NAME}": '[Service]\nExecStart=-"/home/a b/runner/runsvc.sh" x\n'},
            dirs={ETC_UNITS: [self.NAME]},
        )
        self.assertEqual(host.collect().data["github_runners"][0]["directory"], "/home/a b/runner")

    def test_unbalanced_quote_in_exec_start_does_not_fail(self):
        host = Host(
            files={f"{ETC_UNITS}/{self.NAME}": '[Service]\nExecStart=/home/x/runsvc.sh "oops\n'},
            dirs={ETC_UNITS: [self.NAME]},
        )
        self.assertEqual(host.collect().data["github_runners"][0]["directory"], "/home/x")

    def test_other_units_are_not_runners(self):
        host = Host(files={f"{ETC_UNITS}/a.service": "ExecStart=/bin/a\n"}, dirs={ETC_UNITS: ["a.service"]})
        self.assertEqual(host.collect().data["github_runners"], [])

    def test_runner_found_even_when_dpkg_cannot_be_read(self):
        host = self.host()
        host.dirs[DPKG] = PermissionError(13, "denied")
        result = host.collect()
        self.assertIsNone(result.data["units"])
        self.assertEqual(len(result.data["github_runners"]), 1)

    def test_unit_directories_unreadable_is_null(self):
        denied = PermissionError(13, "denied")
        result = Host(dirs={ETC_UNITS: denied, LIB_UNITS: denied}).collect()
        self.assertIsNone(result.data["github_runners"])
        self.assertIn("github_runners", result.source_errors)


class SectionTest(unittest.TestCase):
    def test_lists_are_sorted_whatever_the_listing_order(self):
        def build(reverse):
            names = ["b", "a"] if reverse else ["a", "b"]
            units = ["b.service", "a.service"] if reverse else ["a.service", "b.service"]
            return Host(
                files={
                    "/etc/cron.d/a": "* * * * * root /z\n* * * * * root /a\n",
                    "/etc/cron.d/b": "* * * * * root /b\n",
                    "/etc/sudoers.d/a": "z ALL=ALL\na ALL=ALL\n",
                    "/etc/sudoers.d/b": "b ALL=ALL\n",
                    "/etc/passwd": "zed:x:1:1::/home/zed:/bin/sh\nann:x:2:2::/home/ann:/bin/sh\n",
                    "/home/ann/.ssh/authorized_keys": f"ssh-rsa {key_blob('ssh-rsa', b'2')} b\nssh-rsa {key_blob('ssh-rsa', b'1')}\n",
                    f"{ETC_UNITS}/a.service": "",
                    f"{ETC_UNITS}/b.service": "",
                },
                dirs={"/etc/cron.d": names, "/etc/sudoers.d": names, ETC_UNITS: units},
            ).collect().data

        self.assertEqual(json.dumps(build(False)), json.dumps(build(True)))
        data = build(False)
        self.assertEqual(data["crons"][0]["line"], "* * * * * root /a")
        self.assertEqual([u["name"] for u in data["users"]], ["ann", "zed"])

    def test_no_secret_reaches_the_json(self):
        blob = key_blob("ssh-rsa", b"private-looking")
        host = Host(
            files={
                "/etc/passwd": "alice:$6$HASHVALUE:1000:1000:g:/home/alice:/bin/bash\n",
                "/etc/shadow": "alice:$6$HASHVALUE:::::::\n",
                "/home/alice/.ssh/authorized_keys": (
                    f'command="/bin/run --token=OPT" ssh-rsa {blob} password=COMMENT\n'
                    f"ssh-rsa {key_blob('ssh-rsa', b'second')} ssh-ed25519 {PASTED_KEY}\n"
                ),
                "/etc/crontab": (
                    "* * * * * root curl https://u:CRONPASS@host/x\n"
                    "* * * * * root usermod -p '$6$salt$CRONHASH' bob\n"
                    f"* * * * * root echo ssh-ed25519 {PASTED_KEY} >> /root/.ssh/authorized_keys\n"
                ),
                "/etc/sudoers.d/s": "Defaults mailerpath=/x password=SUDOPASS\n",
                f"{ETC_UNITS}/a.service": "ExecStart=/bin/x --api-key=UNITKEY\nEnvironment=DB_PASSWORD=ENVPASS\n",
            },
            dirs={"/etc/sudoers.d": ["s"], ETC_UNITS: ["a.service"]},
        )
        result = host.collect()
        serialized = json.dumps({"data": result.data, "errors": result.source_errors})
        secrets = ("HASHVALUE", blob, PASTED_KEY, "CRONHASH", "OPT", "COMMENT", "CRONPASS", "SUDOPASS", "UNITKEY", "ENVPASS")
        for secret in secrets:
            self.assertNotIn(secret, serialized)
        self.assertNotIn("/etc/shadow", host.read)

    def test_registered(self):
        self.assertIs(REGISTRY["persistence"], persistence.collect)


if __name__ == "__main__":
    unittest.main()
