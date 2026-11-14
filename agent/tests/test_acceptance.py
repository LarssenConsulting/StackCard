"""End-to-end acceptance cases of the agent 0.5 spec, driven through `sections.run_all`.

StackCard describes the state and does not judge: each test asserts that the data a
verdict needs is present and correct, never the verdict itself.
"""

import base64
import json
import os
import struct
import tempfile
import time
import types
import unittest
from datetime import datetime
from functools import partial

from discover_agent import util
from discover_agent.collectors import techs
from discover_agent.sections import (
    SECTION_NAMES,
    apache,
    apt_sources,
    apt_updates,
    code_patterns,
    docker_images,
    integrity,
    kernel,
    kernel_surface,
    network,
    node_apps,
    packages,
    persistence,
    php,
    restart_needed,
    run_all,
    site_dependencies,
)

ALL_DUE = {"integrity", "code_patterns"}

PACKAGES = (
    "linux-image-6.8.0-147-generic\t6.8.0-147.147\tamd64\tlinux\t6.8.0-147.147\tii \n"
    "php8.3-fpm\t8.3.6-0ubuntu0.24.04.13\tamd64\tphp8.3\t8.3.6-0ubuntu0.24.04.13\tii \n"
    "zlib1g\t1\tamd64\tzlib\t1\tii \n"
)
BTIME = 1700000000


class FakeProc:
    """pid -> (comm, ppid, starttime ticks); 100 ticks per second after boot."""

    def __init__(self, procs, btime=BTIME):
        self.procs = procs
        self.btime = btime
        self.clk_tck = 100

    def pids(self):
        return sorted(self.procs)

    def read(self, pid, name):
        comm, ppid, ticks = self.procs[pid]
        fields = ["S", str(ppid)] + ["0"] * 17 + [str(ticks)] + ["0"] * 5
        return f"{pid} ({comm}) " + " ".join(fields)


class Host:
    """A fake server: command outputs, files, directories and processes."""

    def __init__(self, commands=None, files=None, dirs=None, procs=None, mtimes=None):
        self.commands = commands or {}
        self.files = files or {}
        self.dirs = dirs or {}
        self.procs = procs or {1: ("systemd", 0, 10)}
        self.mtimes = mtimes or {}
        self.calls = []

    def runner(self, cmd, **kwargs):
        self.calls.append(cmd)
        out = self.commands.get(cmd[0])
        if out is None:
            raise util.CommandError(f"command not found: {cmd[0]}")
        if isinstance(out, Exception):
            raise out
        if callable(out):
            return out(cmd)
        return out

    def reader(self, path):
        return self.files.get(path)

    def listdir(self, path):
        return self.dirs.get(path)

    def proc(self):
        return FakeProc(self.procs)

    def mtime(self, path):
        return self.mtimes.get(path)


def sysctl_files(**values):
    return {"/proc/sys/" + name.replace(".", "/"): value + "\n" for name, value in values.items()}


# Traps planted where the five remaining sections read free text or user files.
APACHE_VALUE_TRAP = "ApacheValueTrap"
APACHE_ENV_TRAP = "ApacheEnvTrap"
LOCK_VERSION_TRAP = "LockVersionTrap"
LOCK_DIST_TRAP = "LockDistTrap"
NODE_RESOLVED_TRAP = "NodeResolvedTrap"
NODE_VERSION_TRAP = "NodeVersionTrap"
DOCKER_ENV_TRAP = "DockerEnvTrap"
DOCKER_LABEL_TRAP = "DockerLabelTrap"
DOCKER_CMD_TRAP = "DockerCmdTrap"

APACHE_CONF = (
    "ServerTokens Prod\n"
    "Protocols h2 http/1.1\n"
    f"SetEnv DB_PASSWORD {APACHE_ENV_TRAP}\n"
    f"LimitRequestFieldSize 8190 token={APACHE_VALUE_TRAP}\n"
    "AllowOverride None\n"
)
COMPOSER_LOCK = json.dumps({"packages": [
    {"name": "good/pkg", "version": "1.2.3", "dist": {"url": f"https://u:{LOCK_DIST_TRAP}@h.example/p.zip"}},
    {"name": "bad/pkg", "version": f"https://u:{LOCK_VERSION_TRAP}@h.example/x"},
], "packages-dev": []})
PACKAGE_LOCK = json.dumps({"lockfileVersion": 3, "packages": {
    "": {"name": "api"},
    "node_modules/left-pad": {
        "version": "1.3.0", "resolved": f"https://u:{NODE_RESOLVED_TRAP}@registry.example/left-pad.tgz",
    },
    "node_modules/evil": {"version": f"https://u:{NODE_VERSION_TRAP}@h.example/e.tgz"},
}})
DOCKER_API = {
    "/version": {"Version": "26.1.0"},
    "/images/json": [{
        "Id": "sha256:aa", "RepoTags": ["app:1"], "RepoDigests": ["app@sha256:bb"], "Created": BTIME,
        "Labels": {"secret": DOCKER_LABEL_TRAP},
    }],
    "/containers/json?all=1": [{
        "Names": ["/web"], "Image": "app:1", "ImageID": "sha256:aa", "State": "running", "Ports": [],
        "Labels": {"x": DOCKER_LABEL_TRAP}, "Env": [f"DB_PASSWORD={DOCKER_ENV_TRAP}"],
        "Command": f"run --password={DOCKER_CMD_TRAP}",
    }],
}


def apache_runner(cmd, **kwargs):
    if cmd[:2] == ["apache2ctl", "-M"]:
        return "Loaded Modules:\n core_module (static)\n"
    if cmd[:2] == ["apache2ctl", "-S"]:
        return "VirtualHost configuration:\n*:80 shop.test (/etc/apache2/sites-enabled/shop.conf:1)\n"
    raise AssertionError(cmd)


def node_runner(cmd, **kwargs):
    if cmd[0] == "ss":
        return 'LISTEN 0 511 127.0.0.1:3000 0.0.0.0:* users:(("node",pid=500,fd=19))\n'
    return "20.11.0"


def integrity_runner(cmd, **kwargs):
    return "openssh-server: /etc/ssh/sshd_config\n"


def debsums_full(cmd, **kwargs):
    return 2, "/etc/ssh/sshd_config\n", ""


def extra_sections(host):
    """Fakes for apache, site_dependencies, node_apps, docker, integrity and an empty code scan."""
    files = {
        "/etc/apache2/conf-available/security.conf": APACHE_CONF,
        "/etc/apache2/sites-enabled/shop.conf": "<VirtualHost *:80>\n    ServerName shop.test\n</VirtualHost>\n",
        "/var/www/shop/composer.lock": COMPOSER_LOCK,
        "/srv/api/package-lock.json": PACKAGE_LOCK,
        "/srv/api/package.json": json.dumps({"name": "api", "version": "1.0.0"}),
    }

    def read(path, limit=1024 * 1024):
        return files.get(path)

    return {
        "apache": partial(
            apache.collect, runner=apache_runner, reader=lambda p: files.get(p),
            walker=lambda root, onerror=None: ["/etc/apache2/conf-available/security.conf"],
            islink=lambda p: False, resolve=lambda p: p, read_target=read,
        ),
        "site_dependencies": partial(
            site_dependencies.collect, runner=host.runner, reader=read, exists=lambda p: p in files,
            load_sites=lambda: [{"server_name": "shop.test", "port": 443, "type": "php-fpm",
                                 "app_dir": "/var/www/shop"}],
        ),
        "node_apps": partial(
            node_apps.collect, runner=node_runner, exe_of=lambda pid: "/usr/bin/node",
            proc_info=lambda pid: {"user": "app", "group": "app", "cwd": "/srv/api", "process_manager": None},
            read_package=lambda d: techs.read_package(d, reader=read),
            detect_technologies=lambda d: techs.detect_technologies(d, reader=read),
            reader=read, exists=lambda p: p in files,
        ),
        "docker": partial(docker_images.collect, api=lambda path: DOCKER_API[path], has_docker=lambda: True),
        "integrity": partial(
            integrity.collect, runner=integrity_runner, run_full=debsums_full, modules_visible=lambda: True,
        ),
        "code_patterns": partial(
            code_patterns.collect, runner=host.runner, reader=host.reader,
            vhosts=lambda runner=None, reader=None: [],
        ),
    }


def registry(host, **extra):
    """The real section modules with the fake host injected."""
    reg = {
        "packages": partial(packages.collect, runner=host.runner, mtime=host.mtime),
        "kernel": partial(kernel.collect, runner=host.runner, reader=host.reader),
        "restart_needed": partial(restart_needed.collect, runner=host.runner, proc=host.proc()),
        "kernel_surface": partial(
            kernel_surface.collect, runner=host.runner, reader=host.reader, listdir=host.listdir
        ),
        "network": partial(
            network.collect, runner=host.runner, reader=host.reader, ppid_of={}.get,
            has_ufw=lambda: False, path_exists=lambda path: False,
        ),
        "php": partial(
            php.collect, runner=host.runner, reader=host.reader, listdir=host.listdir,
            proc=host.proc(), load_vhosts=lambda: [], build_context=lambda: {},
        ),
        "persistence": partial(
            persistence.collect, reader=host.reader, list_reader=host.reader,
            listdir=lambda p: host.listdir(p) or [], islink=lambda p: False,
            exists=lambda p: p in host.files,
        ),
        "apt_sources": partial(
            apt_sources.collect, runner=host.runner, reader=host.reader,
            listdir=host.listdir,
        ),
        # Nothing to upgrade, nothing held, fresh package lists: no `apt-get update` is run.
        "apt_updates": partial(
            apt_updates.collect, runner=lambda cmd, **kw: "", run_full=None, refresh_hours=6,
            stat=lambda path: types.SimpleNamespace(st_mtime=time.time() - 60), listdir=lambda p: [],
        ),
    }
    reg.update(extra_sections(host))
    reg.update(extra)
    return reg


def collect(host, **extra):
    return run_all(ALL_DUE, registry=registry(host, **extra))


# ---- case 1: CVE-2026-98432 on prd1 (kernel 6.8.0-147, fix announced in 149)

NFT_TABLE = json.dumps({"nftables": [
    {"table": {"family": "inet", "name": "cve98432", "handle": 1}},
    {"rule": {"family": "inet", "table": "cve98432", "chain": "input", "handle": 2,
              "expr": [{"drop": None}]}},
]})
SS_MPTCP_NONE = ""
SS = 'tcp   LISTEN 0 511  0.0.0.0:80      0.0.0.0:*  users:(("apache2",pid=100,fd=4))\n'


def systemctl(cmd):
    if cmd[1] == "is-enabled":
        return "enabled\n"
    return "active\n"


def prd1(**overrides):
    commands = {
        "uname": "6.8.0-147-generic\n",
        "dpkg-query": PACKAGES,
        "ss": lambda cmd: SS_MPTCP_NONE if "M" in cmd[1] else SS,
        "nft": NFT_TABLE,
        "systemctl": systemctl,
    }
    commands.update(overrides.pop("commands", {}))
    files = {"/proc/stat": f"cpu 1\nbtime {BTIME}\n"}
    files.update(sysctl_files(**{"net.mptcp.enabled": "0", "net.ipv4.tcp_migrate_req": "0"}))
    files.update(overrides.pop("files", {}))
    return Host(commands=commands, files=files, **overrides)


class AcceptanceTest(unittest.TestCase):
    def test_case_1_data(self):
        envelopes = collect(prd1())
        for name in ("kernel", "kernel_surface", "network"):
            self.assertIsNone(envelopes[name]["error"], name)
        self.assertEqual(envelopes["kernel"]["data"]["running"], "6.8.0-147-generic")
        sysctl = envelopes["kernel_surface"]["data"]["sysctl"]
        self.assertEqual(sysctl["net.mptcp.enabled"], "0")
        self.assertEqual(sysctl["net.ipv4.tcp_migrate_req"], "0")
        net = envelopes["network"]["data"]
        self.assertEqual(net["mptcp_listeners"], [])
        tables = [n["table"] for n in net["nft"]["nftables"] if "table" in n]
        self.assertIn(("inet", "cve98432"), [(t["family"], t["name"]) for t in tables])
        self.assertEqual(
            net["services"],
            [{"name": "cve98432-filter.service", "enabled": "enabled", "active": "active"}],
        )

    def test_case_1_data_when_unmitigated(self):
        host = prd1(
            files=sysctl_files(**{"net.mptcp.enabled": "1", "net.ipv4.tcp_migrate_req": "1"}),
            commands={
                "ss": lambda cmd: ('mptcp LISTEN 0 4096 0.0.0.0:443 0.0.0.0:* users:(("mptcpd",pid=9,fd=3))\n'
                                   if "M" in cmd[1] else SS),
                "nft": json.dumps({"nftables": [{"metainfo": {"version": "1.0.9"}}]}),
                "systemctl": lambda cmd: "" if cmd[1] == "is-enabled" else "inactive\n",
            },
        )
        envelopes = collect(host)
        sysctl = envelopes["kernel_surface"]["data"]["sysctl"]
        self.assertEqual((sysctl["net.mptcp.enabled"], sysctl["net.ipv4.tcp_migrate_req"]), ("1", "1"))
        net = envelopes["network"]["data"]
        self.assertEqual([s["port"] for s in net["mptcp_listeners"]], [443])
        self.assertFalse([n for n in net["nft"]["nftables"] if "table" in n])
        self.assertEqual(net["services"][0]["enabled"], "not-found")
        self.assertEqual(net["services"][0]["active"], "inactive")

    # ---- case 2: Fragnesia on a 24.04 GA

    def test_case_2_data(self):
        envelopes = collect(prd1(commands={"uname": "6.8.0-100-generic\n"}))
        data = envelopes["kernel"]["data"]
        self.assertEqual(data["running"], "6.8.0-100-generic")
        self.assertEqual(data["installed"], ["6.8.0-147-generic"])
        self.assertIs(data["reboot_required"], False)

    def test_case_2_data_reboot_missing(self):
        host = prd1(
            commands={"uname": "6.8.0-100-generic\n"},
            files={"/var/run/reboot-required": "*** System restart required ***\n",
                   "/var/run/reboot-required.pkgs": "linux-image-6.8.0-147-generic\n"},
        )
        data = collect(host)["kernel"]["data"]
        self.assertIs(data["reboot_required"], True)
        self.assertEqual(data["reboot_required_pkgs"], ["linux-image-6.8.0-147-generic"])
        self.assertNotIn(data["running"], data["installed"])

    # ---- case 3: PHP2Shell

    def test_case_3_data(self):
        fpm_package = "/var/lib/dpkg/info/php8.3-fpm:amd64.list"
        installed = BTIME + 7200  # two hours after boot
        host = prd1(
            procs={1: ("systemd", 0, 10), 200: ("php-fpm8.3", 1, 100 * 3600), 201: ("php-fpm8.3", 200, 100 * 3601)},
            mtimes={fpm_package: installed},
            commands={"needrestart": "NEEDRESTART-VER: 3.6\nNEEDRESTART-SVC: php8.3-fpm.service\n"},
        )
        envelopes = collect(host)
        pkg = [p for p in envelopes["packages"]["data"] if p["package"] == "php8.3-fpm"][0]
        self.assertEqual(pkg["version"], "8.3.6-0ubuntu0.24.04.13")
        self.assertEqual(pkg["installed_at"], "2023-11-15T00:13:20Z")
        data = envelopes["restart_needed"]["data"]
        self.assertEqual(data["masters"], [{"comm": "php-fpm8.3", "pid": 200, "started_at": "2023-11-14T23:13:20Z"}])
        started = datetime.fromisoformat(data["masters"][0]["started_at"].replace("Z", "+00:00"))
        installed = datetime.fromisoformat(pkg["installed_at"].replace("Z", "+00:00"))
        self.assertLess(started, installed)
        self.assertEqual(data["needrestart"]["services"], ["php8.3-fpm.service"])

    # ---- case 4: CVE-2026-115560

    PHP_I = "Directive => Local Value => Master Value\nsession.upload_progress.enabled => {} => {}\nexpose_php => Off => Off\n"

    def php_host(self, fpm, apache2, cli, modphp=True):
        dirs = {
            "/etc/php": ["8.3"],
            "/etc/php/8.3/fpm": ["php.ini", "pool.d"],
            "/etc/php/8.3/fpm/pool.d": [],
            "/etc/php/8.3/cli": ["php.ini"],
            "/etc/php/8.3/apache2": ["php.ini", "conf.d"],
            "/etc/php/8.3/apache2/conf.d": [],
        }
        return prd1(
            dirs=dirs,
            files={"/etc/php/8.3/apache2/php.ini": f"session.upload_progress.enabled = {apache2}\n"},
            procs={1: ("systemd", 0, 10), 200: ("php-fpm8.3", 1, 100)},
            commands={
                "/usr/sbin/php-fpm8.3": self.PHP_I.format(fpm, fpm),
                "/usr/bin/php8.3": self.PHP_I.format(cli, cli),
                "apache2ctl": "Loaded Modules:\n php_module (shared)\n" if modphp else "Loaded Modules:\n core_module (static)\n",
            },
        )

    def test_case_4_data_all_sapis_off(self):
        sapis = collect(self.php_host("Off", "Off", "Off"))["php"]["data"]["versions"][0]["sapis"]
        for name in ("fpm", "apache2", "cli"):
            self.assertTrue(sapis[name]["active"], name)
            self.assertEqual(sapis[name]["values"]["session.upload_progress.enabled"], "Off", name)

    def test_case_4_data_one_sapi_on(self):
        sapis = collect(self.php_host("Off", "On", "Off"))["php"]["data"]["versions"][0]["sapis"]
        values = {n: sapis[n]["values"]["session.upload_progress.enabled"] for n in ("fpm", "apache2", "cli")}
        self.assertEqual(values, {"fpm": "Off", "apache2": "On", "cli": "Off"})

    def test_case_4_inactive_sapi_is_flagged_inactive(self):
        sapis = collect(self.php_host("Off", "On", "Off", modphp=False))["php"]["data"]["versions"][0]["sapis"]
        self.assertFalse(sapis["apache2"]["active"])

    # ---- case 5: failing collection

    def test_case_5_nft_absent_is_explicit(self):
        host = prd1()
        del host.commands["nft"]
        net = collect(host)["network"]
        self.assertIsNone(net["error"])
        self.assertEqual(net["source_errors"]["nft"], "not_installed")
        self.assertIsNone(net["data"]["nft"])
        self.assertIsNotNone(net["data"]["sockets"])  # the other sources are still sent

    def test_case_5_timeout_is_explicit(self):
        host = prd1(commands={"nft": util.CommandError("timeout after 15s: nft -j list ruleset")})
        net = collect(host)["network"]
        self.assertIn("timeout", net["source_errors"]["nft"])
        self.assertIsNone(net["data"]["nft"])

    def test_case_5_section_timeout_is_explicit(self):
        import threading

        gate = threading.Event()
        self.addCleanup(gate.set)
        envelopes = run_all(ALL_DUE, registry={"kernel": lambda: gate.wait(5)}, timeouts={"kernel": 0.05})
        self.assertEqual(envelopes["kernel"]["error"], "timeout")
        self.assertIsNone(envelopes["kernel"]["data"])

    # ---- case 6: nothing sensitive in the sections

    RAW_KEY = base64.b64encode(struct.pack(">I", 7) + b"ssh-rsa" + b"RAW-KEY-MATERIAL-DO-NOT-LEAK").decode()
    PASSWD_HASH = "$6$saltsalt$HASHVALUEDONOTLEAKHASHVALUEDONOTLEAK"
    CRON_HASH = "$6$cronsalt$CRONHASHDONOTLEAK"
    CRON_PASSWORD = "CronPassw0rd"
    UNIT_PASSWORD = "UnitPassw0rd"
    APT_PASSWORD = "AptPassw0rd"
    POOL_AUTH = "PoolAuthS3cret"
    WP_PASSWORD = "WpDbPassw0rd"

    def test_case_6_no_secret_in_sections(self):
        tmp = tempfile.TemporaryDirectory()
        self.addCleanup(tmp.cleanup)
        webroot = os.path.join(os.path.realpath(tmp.name), "www")
        os.makedirs(webroot)
        with open(os.path.join(webroot, "wp-config.php"), "w") as fh:
            fh.write(
                "<?php\n"
                f"define('DB_PASSWORD', '{self.WP_PASSWORD}');\n"
                "ini_set('session.serialize_handler', 'php_serialize');\n"
            )

        files = {
            "/var/lib/dpkg/info/base.list": "/usr/bin/x\n",
            "/etc/passwd": f"alice:{self.PASSWD_HASH}:1000:1000::/home/alice:/bin/bash\n",
            "/home/alice/.ssh/authorized_keys": f"ssh-rsa {self.RAW_KEY} alice@laptop\n",
            "/etc/cron.d/backup": (
                f"0 3 * * * root usermod -p '{self.CRON_HASH}' bob\n"
                f"5 3 * * * root mysqldump -u root -p{self.CRON_PASSWORD} db\n"
            ),
            "/etc/systemd/system/evil.service": (
                f"[Service]\nExecStart=/opt/evil --password={self.UNIT_PASSWORD}\n"
            ),
            "/etc/apt/sources.list": (
                f"deb https://deploy:{self.APT_PASSWORD}@repo.example.com/ubuntu noble main\n"
            ),
            "/etc/php/8.3/fpm/pool.d/www.conf": (
                "[www]\nuser = www-data\ngroup = www-data\nlisten = /run/php/php8.3-fpm.sock\n"
                f"php_admin_value[session.save_path] = tcp://redis:6379?auth={self.POOL_AUTH}\n"
            ),
        }
        dirs = {
            "/etc/cron.d": ["backup"],
            "/etc/systemd/system": ["evil.service"],
            "/var/lib/dpkg/info": ["base.list"],
            "/etc/php": ["8.3"],
            "/etc/php/8.3/fpm": ["php.ini", "pool.d"],
            "/etc/php/8.3/fpm/pool.d": ["www.conf"],
        }
        host = prd1(
            files=files,
            dirs=dirs,
            commands={"/usr/sbin/php-fpm8.3": (
                "Directive => Local Value => Master Value\n"
                f"session.save_path => tcp://r?auth={self.POOL_AUTH} => no value\n"
                "expose_php => Off => Off\n"
            )},
        )
        extra = {
            "code_patterns": partial(
                code_patterns.collect, runner=host.runner, reader=host.reader,
                vhosts=lambda runner=None, reader=None: [{"document_root": webroot}],
            ),
        }
        envelopes = collect(host, **extra)

        # The sections that hold the planted values really ran; an error would hide a leak.
        self.assertEqual(list(envelopes), list(SECTION_NAMES))
        for name, envelope in envelopes.items():
            self.assertIsNone(envelope["error"], (name, envelope))
        self.assertNotIn("8.3.fpm", envelopes["php"]["source_errors"])
        self.assertEqual(
            envelopes["php"]["data"]["versions"][0]["sapis"]["fpm"]["values"]["expose_php"], "Off"
        )
        self.assertEqual(envelopes["apache"]["source_errors"], {})
        self.assertTrue(envelopes["apache"]["data"]["directives"])
        self.assertEqual(
            [(p["name"], p["version"]) for p in envelopes["site_dependencies"]["data"][0]["composer"]],
            [("bad/pkg", None), ("good/pkg", "1.2.3")],
        )
        self.assertTrue(envelopes["node_apps"]["data"]["apps"])
        self.assertTrue(envelopes["docker"]["data"]["containers"])
        self.assertEqual([r["path"] for r in envelopes["integrity"]["data"]["changed"]], ["/etc/ssh/sshd_config"])
        persistence_data = envelopes["persistence"]["data"]
        self.assertTrue(persistence_data["authorized_keys"])
        self.assertTrue(persistence_data["crons"])
        self.assertTrue(persistence_data["units"])
        self.assertTrue(envelopes["code_patterns"]["data"]["matches"])
        self.assertTrue(envelopes["php"]["data"]["versions"][0]["pools"])

        dump = json.dumps(envelopes)
        for secret in (self.RAW_KEY, self.PASSWD_HASH, self.CRON_HASH, self.CRON_PASSWORD, self.UNIT_PASSWORD,
                       self.APT_PASSWORD, self.POOL_AUTH, self.WP_PASSWORD, "$6$",
                       APACHE_VALUE_TRAP, APACHE_ENV_TRAP, LOCK_VERSION_TRAP, LOCK_DIST_TRAP,
                       NODE_RESOLVED_TRAP, NODE_VERSION_TRAP, DOCKER_ENV_TRAP, DOCKER_LABEL_TRAP,
                       DOCKER_CMD_TRAP):
            self.assertNotIn(secret, dump)


if __name__ == "__main__":
    unittest.main()
