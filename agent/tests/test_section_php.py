import json
import unittest

from discover_agent import util
from discover_agent.collectors import sites as sites_mod
from discover_agent.sections import php

PHP_I = """\
phpinfo()
PHP Version => 8.3.6

Directive => Local Value => Master Value
disable_functions => exec,system => no value
session.upload_progress.enabled => Off => On
session.save_path => tcp://r?auth=S3cret => no value
expose_php => Off => Off

Environment

Variable => Value
SECRET_ENV => x

PHP Variables

Variable => Value
$_ENV['X'] => leaked
"""

CLI_I = "Directive => Local Value => Master Value\nexpose_php => On => On\n"

# The shape of a real `php -i`: only the directive tables and the versions may leave.
FULL_PHP_I = """\
phpinfo()
PHP Version => 8.3.6

System => Linux web01 6.8.0-41-generic
Build Date => Apr 15 2024 19:21:47
Configure Command =>  './configure'  '--with-secret=enabled_marker'
Server API => Command Line Interface

Core

PHP Version => 8.3.6

Directive => Local Value => Master Value
disable_functions => exec,system => no value
expose_php => Off => Off
odd.value => a => b => a => b
session.save_path => tcp://r?auth=S3cret => no value
session.upload_progress.enabled => Off => On
cgi.fix_pathinfo => 1 => 1
mysqli.default_pw => Hunter2 => Hunter2
System Thing Not A Directive
weird key with spaces => leaked => leaked

curl

cURL support => enabled
cURL Information => 8.5.0
SSL Version => OpenSSL/3.0.13
Protocols => http, https

openssl

OpenSSL support => enabled
OpenSSL Library Version => OpenSSL 3.0.13 30 Jan 2024
OpenSSL Header Version => OpenSSL 3.0.13 30 Jan 2024

Directive => Local Value => Master Value
openssl.cafile => no value => no value

phar

Directive => Local Value => Master Value
phar.readonly => Off => On
phar.version_like => 9 => 9

Zend OPcache

Opcode Caching => Disabled

Additional Modules

Module Name

Environment

Variable => Value
PATH => /usr/bin
SECRET_ENV => leaked
VERSION_ENV => leaked

PHP Variables

Variable => Value
$_SERVER['PATH'] => leaked
$_ENV['X'] => leaked
"""

PHP_M = """\
[PHP Modules]
Core
curl
openssl
phar

[Zend Modules]
Zend OPcache

"""

POOL = """\
[www]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
listen.allowed_clients = 127.0.0.1
php_admin_value[session.save_path] = tcp://r?auth=S3cret
php_admin_flag[log_errors] = on
; php_value[ignored] = 1
"""


class FakeProc:
    def __init__(self, comms=()):
        self.comms = comms
        self.btime = 1000
        self.clk_tck = 100

    def pids(self):
        return [1] + [i + 10 for i in range(len(self.comms))]

    def read(self, pid, name):
        comm = "systemd" if pid == 1 else self.comms[pid - 10]
        rest = ["S", "0" if pid == 1 else "1"] + ["0"] * 17 + ["500"]
        return f"{pid} ({comm}) " + " ".join(rest)


def make(files=None, dirs=None, outputs=None, comms=(), calls=None, **overrides):
    files = {} if files is None else files
    dirs = {"/etc/php": ["8.3"]} if dirs is None else dirs
    outputs = {} if outputs is None else outputs

    def runner(cmd, **kw):
        if calls is not None:
            calls.append((cmd, kw))
        out = outputs.get(tuple(cmd), outputs.get(cmd[0]))
        if out is None:
            raise util.CommandError(f"command not found: {cmd[0]}")
        if isinstance(out, Exception):
            raise out
        return out

    values = dict(runner=runner, reader=files.get, listdir=dirs.get, proc=FakeProc(comms))
    values.update(overrides)
    return php.collect(**values)


FULL_DIRS = {
    "/etc/php": ["8.3"],
    "/etc/php/8.3/fpm": ["php.ini", "pool.d"],
    "/etc/php/8.3/fpm/pool.d": ["www.conf"],
    "/etc/php/8.3/cli": ["php.ini"],
}


class ParsePhpITest(unittest.TestCase):
    def test_parse_php_i_keeps_every_directive_with_its_local_value(self):
        values = php.parse_php_i(FULL_PHP_I)
        self.assertEqual(values["phar.readonly"], "Off")
        self.assertEqual(values["cgi.fix_pathinfo"], "1")
        self.assertEqual(values["session.upload_progress.enabled"], "Off")
        self.assertEqual(values["disable_functions"], "exec,system")

    def test_parse_php_i_keeps_nothing_outside_the_directive_tables(self):
        values = php.parse_php_i(FULL_PHP_I)
        text = json.dumps(values)
        for leak in ("web01", "leaked", "SECRET_ENV", "configure", "enabled_marker"):
            self.assertNotIn(leak, text)
        self.assertNotIn("System", values)
        self.assertNotIn("PATH", values)

    def test_parse_php_i_masks_credentials_in_directives(self):
        values = php.parse_php_i(FULL_PHP_I)
        self.assertEqual(values["mysqli.default_pw"], "***")
        self.assertNotIn("Hunter2", json.dumps(values))
        self.assertNotIn("S3cret", json.dumps(values))
        self.assertIn("auth=", values["session.save_path"])

    def test_parse_php_i_value_holding_the_separator(self):
        values = php.parse_php_i(FULL_PHP_I)
        self.assertEqual(values["odd.value"], "a => b")

    def test_missing_key_is_null(self):
        values = php.parse_php_i(CLI_I)
        self.assertEqual(values["expose_php"], "On")
        self.assertIsNone(values["open_basedir"])
        self.assertLessEqual(set(php.PHP_INI), set(values))

    def test_parse_build(self):
        self.assertEqual(php.parse_build(FULL_PHP_I), {"php_version": "8.3.6", "build_date": "Apr 15 2024 19:21:47"})
        self.assertEqual(php.parse_build(CLI_I), {"php_version": None, "build_date": None})


class PoolSettingsTest(unittest.TestCase):
    TEXT = """\
[shop]
user = shop
group = shop
listen = /run/php/shop.sock
pm = dynamic
security.limit_extensions = .php .phar
clear_env = no
listen.mode = "0666"
env[DB_PASSWORD] = Hunter2
ENV[OTHER] = Hunter3
php_admin_value[open_basedir] = /srv/shop
some_token = Hunter4
"""

    def pool(self):
        return php.parse_pool_file(self.TEXT, "/etc/php/8.4/fpm/pool.d/1793121754194971.conf")[0]

    def test_every_directive_of_the_pool_file_is_kept(self):
        pool = self.pool()
        self.assertEqual(pool["settings"], {
            "pm": "dynamic",
            "security.limit_extensions": ".php .phar",
            "clear_env": "no",
            "listen.mode": "0666",
            "some_token": "***",
        })
        self.assertEqual(pool["file"], "/etc/php/8.4/fpm/pool.d/1793121754194971.conf")
        self.assertEqual(pool["php_values"][0]["key"], "open_basedir")

    def test_env_lines_never_leave(self):
        text = json.dumps(self.pool())
        for leak in ("Hunter2", "Hunter3", "Hunter4", "DB_PASSWORD", "OTHER"):
            self.assertNotIn(leak, text)


class ParseExtensionsTest(unittest.TestCase):
    def test_names_with_the_versions_phpinfo_shows(self):
        extensions = php.parse_extensions(PHP_M, FULL_PHP_I)
        self.assertEqual([e["name"] for e in extensions], ["Core", "curl", "openssl", "phar", "Zend OPcache"])
        by_name = {e["name"]: e["versions"] for e in extensions}
        self.assertEqual(by_name["openssl"], {
            "OpenSSL Library Version": "OpenSSL 3.0.13 30 Jan 2024",
            "OpenSSL Header Version": "OpenSSL 3.0.13 30 Jan 2024",
        })
        self.assertEqual(by_name["curl"], {"cURL Information": "8.5.0", "SSL Version": "OpenSSL/3.0.13"})
        self.assertEqual(by_name["Zend OPcache"], {})

    def test_directives_and_environment_are_not_versions(self):
        extensions = php.parse_extensions(PHP_M, FULL_PHP_I)
        by_name = {e["name"]: e["versions"] for e in extensions}
        self.assertNotIn("phar.version_like", by_name["phar"])
        self.assertNotIn("VERSION_ENV", json.dumps(extensions))

    def test_noise_in_the_module_list_is_dropped(self):
        extensions = php.parse_extensions("PHP Warning:  Module already loaded in Unknown\n[PHP Modules]\nCore\n\n", "")
        self.assertEqual(extensions, [{"name": "Core", "versions": {}}])

    def test_duplicate_name_is_listed_once(self):
        extensions = php.parse_extensions("[PHP Modules]\nCore\n[Zend Modules]\nCore\n", "")
        self.assertEqual(len(extensions), 1)


class CommandTest(unittest.TestCase):
    def test_commands_are_absolute_with_clean_env(self):
        calls = []
        make(files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL}, dirs=FULL_DIRS,
             outputs={"/usr/sbin/php-fpm8.3": PHP_I, "/usr/bin/php8.3": CLI_I}, calls=calls)
        by_cmd = {tuple(c): kw for c, kw in calls}
        self.assertIn(("/usr/bin/php8.3", "-i"), by_cmd)
        self.assertIn(("/usr/sbin/php-fpm8.3", "-i"), by_cmd)
        for cmd, kw in by_cmd.items():
            if cmd[0].startswith("/usr/"):
                self.assertEqual(kw["cwd"], "/")
                self.assertEqual(set(kw["env"]), {"PATH", "LC_ALL"})

    def test_version_dir_must_match_regex(self):
        calls = []
        result = make(dirs={"/etc/php": ["8.3;rm", "../x", "8.3.1"]}, calls=calls)
        self.assertEqual(result.data["versions"], [])
        self.assertFalse([c for c, _ in calls if c[0].startswith("/usr/")])

    def test_no_php_is_not_installed(self):
        with self.assertRaises(util.NotInstalled):
            make(dirs={})


class SapiTest(unittest.TestCase):
    def sapis(self, **kw):
        base = dict(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL},
                    outputs={"/usr/sbin/php-fpm8.3": PHP_I, "/usr/bin/php8.3": CLI_I,
                             "apache2ctl": "Loaded Modules:\n core_module (static)\n"})
        base.update(kw)
        return make(**base)

    def test_fpm_and_cli_values(self):
        result = self.sapis(comms=["php-fpm8.3"])
        sapis = result.data["versions"][0]["sapis"]
        self.assertTrue(sapis["fpm"]["active"])
        self.assertEqual(sapis["fpm"]["source"], "php -i")
        self.assertEqual(sapis["fpm"]["values"]["session.upload_progress.enabled"], "Off")
        self.assertTrue(sapis["cli"]["active"])
        self.assertEqual(sapis["cli"]["values"]["expose_php"], "On")
        self.assertEqual(result.source_errors, {})

    def test_build_and_extensions_per_sapi(self):
        result = self.sapis(outputs={
            ("/usr/bin/php8.3", "-i"): FULL_PHP_I, ("/usr/bin/php8.3", "-m"): PHP_M,
            ("/usr/sbin/php-fpm8.3", "-i"): PHP_I, ("/usr/sbin/php-fpm8.3", "-m"): "[PHP Modules]\nCore\n",
        })
        sapis = result.data["versions"][0]["sapis"]
        self.assertEqual(sapis["cli"]["php_version"], "8.3.6")
        self.assertEqual(sapis["cli"]["build_date"], "Apr 15 2024 19:21:47")
        self.assertEqual(sapis["cli"]["values"]["phar.readonly"], "Off")
        self.assertIn("curl", [e["name"] for e in sapis["cli"]["extensions"]])
        self.assertEqual([e["name"] for e in sapis["fpm"]["extensions"]], ["Core"])

    def test_modules_failure_keeps_the_values(self):
        result = self.sapis(outputs={
            ("/usr/bin/php8.3", "-i"): FULL_PHP_I, ("/usr/bin/php8.3", "-m"): util.CommandError("boom"),
            "/usr/sbin/php-fpm8.3": PHP_I,
        })
        cli = result.data["versions"][0]["sapis"]["cli"]
        self.assertEqual(cli["values"]["phar.readonly"], "Off")
        self.assertIsNone(cli["extensions"])
        self.assertIn("boom", result.source_errors["8.3.cli.extensions"])

    def test_php_runs_clean_for_modules_too(self):
        calls = []
        make(dirs=FULL_DIRS, outputs={"/usr/sbin/php-fpm8.3": PHP_I, "/usr/bin/php8.3": CLI_I}, calls=calls)
        by_cmd = {tuple(c): kw for c, kw in calls}
        for cmd in (("/usr/bin/php8.3", "-m"), ("/usr/sbin/php-fpm8.3", "-m")):
            self.assertEqual(by_cmd[cmd]["cwd"], "/")
            self.assertEqual(set(by_cmd[cmd]["env"]), {"PATH", "LC_ALL"})

    def test_mod_php_has_no_build_or_extensions(self):
        dirs = {"/etc/php": ["8.3"], "/etc/php/8.3/apache2": ["php.ini"]}
        result = make(files={"/etc/php/8.3/apache2/php.ini": "expose_php = On\n"}, dirs=dirs,
                      outputs={"apache2ctl": "php_module (shared)\n"})
        apache2 = result.data["versions"][0]["sapis"]["apache2"]
        self.assertIsNone(apache2["php_version"])
        self.assertIsNone(apache2["build_date"])
        self.assertIsNone(apache2["extensions"])
        self.assertEqual(result.source_errors, {})

    def test_mod_php_keeps_every_ini_directive_but_not_extension_lines(self):
        dirs = {"/etc/php": ["8.3"], "/etc/php/8.3/apache2": ["php.ini"]}
        ini = "phar.readonly = Off\nextension=gd.so\nzend_extension=opcache.so\nmysqli.default_pw = Hunter2\nbad key = 1\n"
        result = make(files={"/etc/php/8.3/apache2/php.ini": ini}, dirs=dirs,
                      outputs={"apache2ctl": "php_module (shared)\n"})
        values = result.data["versions"][0]["sapis"]["apache2"]["values"]
        self.assertEqual(values["phar.readonly"], "Off")
        self.assertNotIn("extension", values)
        self.assertNotIn("zend_extension", values)
        self.assertNotIn("bad key", values)
        self.assertEqual(values["mysqli.default_pw"], "***")

    def test_sapi_active(self):
        no_master = self.sapis(comms=["php-fpm8.4", "bash"])
        self.assertFalse(no_master.data["versions"][0]["sapis"]["fpm"]["active"])
        dirs = dict(FULL_DIRS, **{"/etc/php/8.3/apache2": ["php.ini", "conf.d"]})
        mod = self.sapis(dirs=dirs, files={"/etc/php/8.3/apache2/php.ini": "expose_php = On\n"},
                         outputs={"apache2ctl": "Loaded Modules:\n php_module (shared)\n"})
        self.assertTrue(mod.data["versions"][0]["sapis"]["apache2"]["active"])
        absent = self.sapis(dirs=dirs, files={"/etc/php/8.3/apache2/php.ini": "expose_php = On\n"})
        self.assertFalse(absent.data["versions"][0]["sapis"]["apache2"]["active"])

    def test_mod_php_reads_ini_files(self):
        dirs = {"/etc/php": ["8.3"], "/etc/php/8.3/apache2": ["php.ini", "conf.d"],
                "/etc/php/8.3/apache2/conf.d": ["20-b.ini", "10-a.ini", "README"]}
        files = {
            "/etc/php/8.3/apache2/php.ini": "; c\n[PHP]\nexpose_php = On\ndisplay_errors = Off\nsecret_thing = 1\n",
            "/etc/php/8.3/apache2/conf.d/10-a.ini": "expose_php = Off\nopen_basedir = \"/srv\"\n",
            "/etc/php/8.3/apache2/conf.d/20-b.ini": "open_basedir = /var/www\nsession.save_path = tcp://r?auth=S3cret\n",
            "/etc/php/8.3/apache2/conf.d/README": "expose_php = Maybe\n",
        }
        result = make(files=files, dirs=dirs, outputs={"apache2ctl": "php_module (shared)\n"})
        apache2 = result.data["versions"][0]["sapis"]["apache2"]
        self.assertEqual(apache2["source"], "ini")
        self.assertEqual(apache2["values"]["expose_php"], "Off")
        self.assertEqual(apache2["values"]["open_basedir"], "/var/www")
        self.assertEqual(apache2["values"]["display_errors"], "Off")
        self.assertNotIn("S3cret", json.dumps(apache2))

    def test_php_i_save_path_is_redacted(self):
        result = self.sapis()
        fpm = result.data["versions"][0]["sapis"]["fpm"]["values"]
        self.assertNotIn("S3cret", json.dumps(result.data))
        self.assertIn("auth=", fpm["session.save_path"])

    def test_missing_binary_with_config_dir_is_a_source_error(self):
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL},
                      outputs={"/usr/bin/php8.3": CLI_I})
        fpm = result.data["versions"][0]["sapis"]["fpm"]
        self.assertFalse(fpm["active"])
        self.assertIsNone(fpm["values"])
        self.assertEqual(result.source_errors["8.3.fpm"], "not_installed")

    def test_missing_binary_without_config_dir_is_silent(self):
        result = make(dirs={"/etc/php": ["8.3"], "/etc/php/8.3/cli": ["php.ini"]},
                      outputs={"/usr/bin/php8.3": CLI_I})
        sapis = result.data["versions"][0]["sapis"]
        self.assertIsNone(sapis["fpm"]["values"])
        self.assertFalse(sapis["apache2"]["active"])
        self.assertEqual(result.source_errors, {})

    def test_command_failure_is_reported_redacted(self):
        err = util.CommandError("/usr/bin/php8.3 exited 1: boom")
        result = make(dirs=FULL_DIRS, outputs={"/usr/bin/php8.3": err})
        self.assertIsNone(result.data["versions"][0]["sapis"]["cli"]["values"])
        self.assertIn("exited 1", result.source_errors["8.3.cli"])


class PoolTest(unittest.TestCase):
    def test_pool_values_redacted(self):
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL},
                      outputs={"/usr/bin/php8.3": CLI_I})
        pool = result.data["versions"][0]["pools"][0]
        self.assertEqual(pool["name"], "www")
        self.assertEqual(pool["listen"], "/run/php/php8.3-fpm.sock")
        self.assertEqual(pool["listen_allowed_clients"], "127.0.0.1")
        self.assertEqual((pool["user"], pool["group"]), ("www-data", "www-data"))
        self.assertEqual(len(pool["php_values"]), 2)
        self.assertNotIn("S3cret", json.dumps(result.data))
        save_path = [v for v in pool["php_values"] if v["key"] == "session.save_path"][0]
        self.assertEqual(save_path["directive"], "php_admin_value")
        self.assertIn("auth=", save_path["value"])


VHOST = {
    "server_name": "a.example.com", "aliases": [], "port": 443, "ssl": True, "cert_file": None,
    "document_root": "/var/www/a/public", "proxy_pass": None, "fpm_socket": "/run/php/php8.3-fpm.sock",
    "assign_user": None, "config_file": "/etc/apache2/sites-enabled/a.conf", "partial": False,
}

APACHE_CONF = """\
<Directory />
    AllowOverride None
</Directory>
<Directory /var/www/>
    AllowOverride None
</Directory>
"""
SITE_CONF = """\
<VirtualHost *:443>
    <Directory "/var/www/a">
        AllowOverride FileInfo Options
    </Directory>
</VirtualHost>
"""


def site_run(vhosts=(VHOST,), apache_conf=APACHE_CONF, site_conf=SITE_CONF, files_extra=None,
             dirs_override=None, outputs_extra=None, **overrides):
    dirs = dict(FULL_DIRS)
    dirs.update({"/etc/apache2/conf-enabled": [], "/etc/apache2/sites-enabled": ["a.conf"]})
    dirs.update(dirs_override or {})
    files = {
        "/etc/php/8.3/fpm/pool.d/www.conf": POOL,
        "/etc/apache2/apache2.conf": apache_conf,
        "/etc/apache2/sites-enabled/a.conf": site_conf,
    }
    files.update(files_extra or {})
    outputs = {"/usr/bin/php8.3": CLI_I, "apache2ctl": "php_module (shared)\n"}
    outputs.update(outputs_extra or {})

    def classify(vhost, ctx):
        site = sites_mod._empty_site(vhost)
        site["type"] = "php-fpm"
        site["php_version"] = "8.3"
        return site

    values = dict(
        dirs=dirs, files=files,
        outputs=outputs,
        load_vhosts=lambda: list(vhosts), build_context=lambda: None, classify=classify,
    )
    values.update(overrides)
    return make(**values)


class AllowOverrideTest(unittest.TestCase):
    def test_site_allow_override(self):
        result = site_run()
        site = result.data["sites"][0]
        self.assertEqual(site["allow_override"], "FileInfo Options")
        self.assertEqual(site["php_version"], "8.3")
        self.assertEqual(site["mode"], "fpm")
        self.assertEqual(site["pool"], "www")

    def test_global_directory_applies_when_no_vhost_block_matches(self):
        result = site_run(site_conf="<VirtualHost *:443>\n</VirtualHost>\n")
        self.assertEqual(result.data["sites"][0]["allow_override"], "None")

    def test_none_when_nothing_matches(self):
        result = site_run(apache_conf="", site_conf="")
        self.assertIsNone(result.data["sites"][0]["allow_override"])

    def test_effective_allow_override_most_specific(self):
        dirs = [
            {"path": "/var/www/*/public", "allow_override": "Indexes"},
            {"path": "/var/www", "allow_override": "None"},
            {"path": "/var/www/a/public", "allow_override": "All"},
            {"path": "/var/www/a", "allow_override": "Limit"},
            {"path": "/var/ww", "allow_override": "AuthConfig"},
        ]
        self.assertEqual(php.effective_allow_override("/var/www/a/public", dirs), "All")
        self.assertEqual(php.effective_allow_override("/var/www/b/other", dirs), "None")
        self.assertEqual(php.effective_allow_override("/var/www/b/public/", dirs), "Indexes")
        self.assertIsNone(php.effective_allow_override("/srv/x", dirs))
        self.assertIsNone(php.effective_allow_override(None, dirs))

    def test_apache_absent_gives_no_sites_and_no_error(self):
        result = make(dirs=FULL_DIRS, outputs={"/usr/bin/php8.3": CLI_I, "/usr/sbin/php-fpm8.3": PHP_I})
        self.assertEqual(result.data["sites"], [])
        self.assertEqual(result.source_errors, {})

    def test_mod_php_site(self):
        def classify(vhost, ctx):
            site = sites_mod._empty_site(vhost)
            site["type"] = "php-mod"
            return site

        vhost = dict(VHOST, fpm_socket=None)
        result = site_run(vhosts=[vhost], classify=classify)
        site = result.data["sites"][0]
        self.assertEqual(site["mode"], "mod_php")
        self.assertIsNone(site["pool"])

    def test_vhost_failure_is_a_source_error(self):
        def boom():
            raise util.CommandError("apache2ctl -S exited 1: bad")

        result = site_run(load_vhosts=boom)
        self.assertIsNone(result.data["sites"])
        self.assertIn("sites", result.source_errors)


class RoundOneTest(unittest.TestCase):
    def apache2(self, files, mod_text="php_module (shared)\n", dirs=None):
        dirs = dirs or {"/etc/php": ["8.3"], "/etc/php/8.3/apache2": ["php.ini"]}
        result = make(files=files, dirs=dirs, outputs={"apache2ctl": mod_text})
        return result, result.data["versions"][0]["sapis"]["apache2"]

    def test_empty_ini_value_does_not_crash(self):
        result, apache2 = self.apache2({"/etc/php/8.3/apache2/php.ini": "disable_functions =\nexpose_php = Off\n"})
        self.assertEqual(apache2["values"]["disable_functions"], "")
        self.assertEqual(apache2["values"]["expose_php"], "Off")

    def test_unreadable_php_ini_is_a_source_error_not_a_crash(self):
        result, apache2 = self.apache2({})
        self.assertIsNone(apache2["values"])
        self.assertIn("unreadable", result.source_errors["8.3.apache2"])

    def test_path_and_host_sections_do_not_fold_into_globals(self):
        ini = "expose_php = Off\n[PATH=/srv/x]\nexpose_php = On\nopen_basedir = /srv\n[HOST=h]\ndisplay_errors = On\n[Date]\nallow_url_fopen = Off\n"
        _, apache2 = self.apache2({"/etc/php/8.3/apache2/php.ini": ini})
        values = apache2["values"]
        self.assertEqual(values["expose_php"], "Off")
        self.assertIsNone(values["open_basedir"])
        self.assertIsNone(values["display_errors"])
        self.assertEqual(values["allow_url_fopen"], "Off")

    def test_apache2ctl_failure_gives_unknown_activity_absent_gives_false(self):
        dirs = {"/etc/php": ["8.3"], "/etc/php/8.3/apache2": ["php.ini"]}
        files = {"/etc/php/8.3/apache2/php.ini": "expose_php = On\n"}
        failed = make(files=files, dirs=dirs, outputs={"apache2ctl": util.CommandError("apache2ctl -M exited 1: bad")})
        self.assertIsNone(failed.data["versions"][0]["sapis"]["apache2"]["active"])
        self.assertIn("apache2ctl", failed.source_errors)
        absent = make(files=files, dirs=dirs)
        self.assertIs(absent.data["versions"][0]["sapis"]["apache2"]["active"], False)
        self.assertNotIn("apache2ctl", absent.source_errors)

    def test_removed_version_is_not_active(self):
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL}, comms=["php-fpm8.3"])
        sapis = result.data["versions"][0]["sapis"]
        self.assertIs(sapis["cli"]["active"], False)
        self.assertIsNone(sapis["cli"]["values"])
        self.assertIs(sapis["fpm"]["active"], False)

    def test_php_i_has_a_timeout(self):
        calls = []
        make(dirs=FULL_DIRS, outputs={"/usr/bin/php8.3": CLI_I, "/usr/sbin/php-fpm8.3": PHP_I}, calls=calls)
        timeouts = [kw["timeout"] for cmd, kw in calls if cmd[0].startswith("/usr/")]
        self.assertEqual(timeouts, [5, 5, 5, 5])  # -i and -m for fpm and cli

    def test_php_i_without_expose_php_is_a_parse_error(self):
        result = make(dirs=FULL_DIRS, outputs={"/usr/bin/php8.3": "nothing useful\n", "/usr/sbin/php-fpm8.3": PHP_I})
        cli = result.data["versions"][0]["sapis"]["cli"]
        self.assertIsNone(cli["values"])
        self.assertIn("expose_php", result.source_errors["8.3.cli"])

    def test_pool_listen_quotes_stripped_and_global_skipped(self):
        pool = '[global]\npid = /run/x.pid\n[www]\nlisten = "/run/php/php8.3-fpm.sock"\nuser = u\n'
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": pool}, outputs={"/usr/bin/php8.3": CLI_I})
        pools = result.data["versions"][0]["pools"]
        self.assertEqual([p["name"] for p in pools], ["www"])
        self.assertEqual(pools[0]["listen"], "/run/php/php8.3-fpm.sock")
        sited = site_run(files_extra={"/etc/php/8.3/fpm/pool.d/www.conf": pool})
        self.assertEqual(sited.data["sites"][0]["pool"], "www")

    def test_mod_php_version_from_mods_enabled_with_several_dirs(self):
        def classify(vhost, ctx):
            site = sites_mod._empty_site(vhost)
            site["type"] = "php-mod"
            return site

        dirs = {"/etc/php": ["8.1", "8.3"], "/etc/php/8.1/apache2": [], "/etc/php/8.3/apache2": [],
                "/etc/apache2/sites-enabled": ["a.conf"]}
        extra = {"/etc/apache2/mods-enabled/php8.3.load": "LoadModule php_module x\n"}
        result = site_run(vhosts=[dict(VHOST, fpm_socket=None)], classify=classify, dirs_override=dirs, files_extra=extra)
        self.assertEqual(result.data["sites"][0]["php_version"], "8.3")
        sapis = {v["version"]: v["sapis"]["apache2"]["active"] for v in result.data["versions"]}
        self.assertEqual(sapis, {"8.1": False, "8.3": True})
        result = site_run(vhosts=[dict(VHOST, fpm_socket=None)], classify=classify, dirs_override=dirs)
        self.assertIsNone(result.data["sites"][0]["php_version"])


class SecretsTest(unittest.TestCase):
    def pool_values(self, lines):
        pool = "[www]\nlisten = /run/x.sock\nlisten.allowed_clients = 127.0.0.1\n" + lines
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": pool}, outputs={"/usr/bin/php8.3": CLI_I})
        return result, {v["key"]: v["value"] for v in result.data["versions"][0]["pools"][0]["php_values"]}

    def test_sensitive_keys_mask_the_whole_value(self):
        result, values = self.pool_values(
            "php_admin_value[mysqli.default_pw] = S3cret\n"
            "php_admin_value[mysql.default_password] = a b\n"
            "php_admin_value[newrelic.license] = abc123\n"
            "php_admin_value[mysqli.default_pwd] = Zed\n")
        self.assertEqual(set(values.values()), {"***"})
        text = json.dumps(result.data)
        for leaked in ("S3cret", '"b"', "abc123", "Zed", "a b"):
            self.assertNotIn(leaked, text)

    def test_array_auth_in_save_path_is_masked(self):
        for raw in ("tcp://h:6379?auth[]=user&auth[]=S3cret", "tcp://h:6379?auth[user]=u&auth[pass]=S3cret"):
            _, values = self.pool_values(f"php_admin_value[session.save_path] = {raw}\n")
            self.assertNotIn("S3cret", values["session.save_path"])
            self.assertIn("tcp://h:6379", values["session.save_path"])

    def test_ordinary_values_stay_intact(self):
        _, values = self.pool_values(
            "php_admin_value[disable_functions] = exec,passthru,shell_exec\n"
            "php_admin_value[open_basedir] = /var/www/a:/tmp\n"
            "php_admin_value[session.save_path] = /var/lib/php/sessions\n")
        self.assertEqual(values["disable_functions"], "exec,passthru,shell_exec")
        self.assertEqual(values["open_basedir"], "/var/www/a:/tmp")
        self.assertEqual(values["session.save_path"], "/var/lib/php/sessions")
        result = make(dirs=FULL_DIRS, files={"/etc/php/8.3/fpm/pool.d/www.conf": POOL}, outputs={"/usr/bin/php8.3": CLI_I})
        self.assertEqual(result.data["versions"][0]["pools"][0]["listen_allowed_clients"], "127.0.0.1")


class ApacheConfigTest(unittest.TestCase):
    def run_site(self, apache_conf="", site_conf=SITE_CONF, extra=None, modules="php_module (shared)\n",
                 vhost=VHOST, sites_enabled=("a.conf",)):
        files = {"/etc/apache2/apache2.conf": apache_conf, "/etc/apache2/sites-enabled/a.conf": site_conf}
        files.update(extra or {})
        dirs = {"/etc/apache2/sites-enabled": list(sites_enabled)}
        dirs.update({k: v for k, v in (extra or {}).items() if isinstance(v, list)})
        files = {k: v for k, v in files.items() if isinstance(v, str)}
        result = site_run(vhosts=[vhost], files_extra=files, dirs_override=dict(FULL_DIRS, **dirs),
                          outputs_extra={"apache2ctl": modules})
        return result.data["sites"][0]["allow_override"]

    def test_mods_enabled_conf_is_read(self):
        extra = {"/etc/apache2/mods-enabled": ["x.conf"],
                 "/etc/apache2/mods-enabled/x.conf": "<Directory /var/www/a>\nAllowOverride Indexes\n</Directory>\n"}
        self.assertEqual(self.run_site(site_conf="<VirtualHost *:443>\n</VirtualHost>\n", extra=extra), "Indexes")

    def test_include_and_include_optional_are_followed(self):
        conf = "Include extra/*.conf\nIncludeOptional missing/*.conf\n"
        extra = {"/etc/apache2/extra": ["d.conf"],
                 "/etc/apache2/extra/d.conf": "<Directory /var/www/a>\nAllowOverride All\n</Directory>\n"}
        self.assertEqual(self.run_site(apache_conf=conf, site_conf="<VirtualHost *:443>\n</VirtualHost>\n", extra=extra), "All")

    def test_include_inside_vhost_keeps_the_vhost_context(self):
        site = "<VirtualHost *:443>\nInclude /etc/apache2/snip.conf\n</VirtualHost>\n<VirtualHost *:80>\n</VirtualHost>\n"
        extra = {"/etc/apache2/snip.conf": "<Directory /var/www/a>\nAllowOverride All\n</Directory>\n"}
        self.assertEqual(self.run_site(site_conf=site, extra=extra, vhost=dict(VHOST, line=1)), "All")
        self.assertIsNone(self.run_site(site_conf=site, extra=extra, vhost=dict(VHOST, line=3)))

    def test_unreadable_include_gives_null(self):
        conf = "<Directory /var/www>\nAllowOverride None\n</Directory>\nInclude /etc/apache2/gone.conf\n"
        self.assertIsNone(self.run_site(apache_conf=conf, site_conf="<VirtualHost *:443>\n</VirtualHost>\n"))

    def test_include_depth_is_limited(self):
        extra = {f"/etc/apache2/l{i}.conf": f"Include /etc/apache2/l{i + 1}.conf\n" for i in range(7)}
        extra["/etc/apache2/l7.conf"] = "<Directory /var/www/a>\nAllowOverride All\n</Directory>\n"
        self.assertIsNone(self.run_site(apache_conf="Include /etc/apache2/l0.conf\n", extra=extra,
                                        site_conf="<VirtualHost *:443>\n</VirtualHost>\n"))

    def test_sibling_vhosts_in_one_file_do_not_share_directories(self):
        site = ("<VirtualHost *:80>\n<Directory /var/www/a>\nAllowOverride None\n</Directory>\n</VirtualHost>\n"
                "<VirtualHost *:443>\n<Directory /var/www/a>\nAllowOverride All\n</Directory>\n</VirtualHost>\n")
        self.assertEqual(self.run_site(site_conf=site, vhost=dict(VHOST, line=1)), "None")
        self.assertEqual(self.run_site(site_conf=site, vhost=dict(VHOST, line=6)), "All")
        self.assertIsNone(self.run_site(site_conf=site))  # no line and the siblings disagree

    def test_tab_separated_directive(self):
        site = "<VirtualHost *:443>\n<Directory /var/www/a>\n\tAllowOverride\tAll\n</Directory>\n</VirtualHost>\n"
        self.assertEqual(self.run_site(site_conf=site), "All")

    def test_allow_override_inside_ifmodule_counts(self):
        site = ("<VirtualHost *:443>\n<Directory /var/www/a>\n<IfModule php_module>\nAllowOverride All\n"
                "</IfModule>\n</Directory>\n</VirtualHost>\n")
        self.assertEqual(self.run_site(site_conf=site), "All")

    def test_negated_ifmodule_and_macro_are_not_active(self):
        site = ("<VirtualHost *:443>\n<Directory /var/www/a>\nAllowOverride Limit\n<IfModule !php_module>\n"
                "AllowOverride All\n</IfModule>\n</Directory>\n</VirtualHost>\n")
        self.assertEqual(self.run_site(site_conf=site), "Limit")
        macro = "<Macro m>\n<Directory /var/www/a>\nAllowOverride All\n</Directory>\n</Macro>\n<VirtualHost *:443>\n</VirtualHost>\n"
        self.assertIsNone(self.run_site(site_conf=macro))

    def test_unevaluable_condition_gives_null(self):
        site = ("<VirtualHost *:443>\n<Directory /var/www/a>\n<IfDefine PROD>\nAllowOverride All\n"
                "</IfDefine>\n</Directory>\n</VirtualHost>\n")
        self.assertIsNone(self.run_site(site_conf=site))
        site = "<VirtualHost *:443>\n<Directory /var/www/a>\n<IfModule foo_module>\nAllowOverride All\n</IfModule>\n</Directory>\n</VirtualHost>\n"
        self.assertIsNone(self.run_site(site_conf=site, modules=util.CommandError("apache2ctl -M exited 1: x")))

    def test_directory_match_with_allow_override_gives_null(self):
        site = "<VirtualHost *:443>\n<DirectoryMatch ^/var/www/a>\nAllowOverride All\n</DirectoryMatch>\n</VirtualHost>\n"
        self.assertIsNone(self.run_site(site_conf=site))


class NoCertificateCommandsTest(unittest.TestCase):
    def test_default_context_runs_no_openssl_per_vhost(self):
        calls = []

        def runner(cmd, **kw):
            calls.append(cmd)
            if cmd[0] == "ss":
                return ""
            raise util.CommandError("exited 1")

        vhost = {
            "server_name": "a.test", "aliases": [], "port": 443, "document_root": "/srv/a", "ssl": True,
            "cert_file": "/etc/ssl/a.pem", "fpm_socket": None, "proxy_pass": None, "partial": False,
            "config_file": "/etc/apache2/sites-enabled/a.conf", "line": 1,
        }
        php._collect_sites(runner, {}.get, lambda path: None, lambda: [vhost], None, sites_mod.classify, {}, None, set())
        self.assertFalse([c for c in calls if c[0] == "openssl"], calls)


if __name__ == "__main__":
    unittest.main()
