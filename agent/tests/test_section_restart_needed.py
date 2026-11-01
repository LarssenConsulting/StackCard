import unittest

from discover_agent import util
from discover_agent.sections import restart_needed

NEEDRESTART = """\
NEEDRESTART-VER: 3.6
NEEDRESTART-KCUR: 6.8.0-147-generic
NEEDRESTART-KEXP: 6.8.0-150-generic
NEEDRESTART-KSTA: 3
NEEDRESTART-SVC: ssh.service
NEEDRESTART-SVC: apache2.service
NEEDRESTART-SVC: apache2.service
"""


class FakeProc:
    """pid -> (comm, ppid, starttime ticks); /proc/stat btime 1700000000, 100 ticks/s."""

    def __init__(self, procs, btime=1700000000):
        self.procs = procs
        self.btime = btime

    def pids(self):
        return sorted(self.procs)

    def read(self, pid, name):
        comm, ppid, ticks = self.procs[pid]
        fields = ["S", str(ppid)] + ["0"] * 17 + [str(ticks)] + ["0"] * 5
        return f"{pid} ({comm}) " + " ".join(fields)


PROCS = {
    1: ("systemd", 0, 10),
    100: ("apache2", 1, 360000),  # 3600 s after boot
    101: ("apache2", 100, 360500),
    102: ("apache2", 100, 360600),
    200: ("php-fpm8.3", 1, 720000),
    201: ("php-fpm8.3", 200, 720100),
    300: ("php-fpm8.2", 1, 100),
    400: ("bash", 1, 5),
}


def proc():
    return FakeProc(PROCS)


class MasterPidsTest(unittest.TestCase):
    def test_only_masters_are_returned(self):
        masters = restart_needed.master_pids("apache2", proc())
        self.assertEqual(masters, [{"comm": "apache2", "pid": 100, "started_at": "2023-11-14T23:13:20Z"}])

    def test_prefix_matches_versions_sorted(self):
        masters = restart_needed.master_pids("php-fpm", proc())
        self.assertEqual([(m["comm"], m["pid"]) for m in masters], [("php-fpm8.2", 300), ("php-fpm8.3", 200)])
        self.assertEqual(masters[1]["started_at"], "2023-11-15T00:13:20Z")

    def test_unreadable_process_is_skipped(self):
        class Flaky(FakeProc):
            def read(self, pid, name):
                if pid == 100:
                    return None
                return super().read(pid, name)

        self.assertEqual(restart_needed.master_pids("apache2", Flaky(PROCS)), [])

    def test_no_btime_means_no_start_time_but_no_crash(self):
        masters = restart_needed.master_pids("apache2", FakeProc(PROCS, btime=None))
        self.assertEqual(masters[0]["started_at"], None)


timeouts = []


def collect(output=NEEDRESTART, **overrides):
    calls = []

    def runner(cmd, **kw):
        calls.append(cmd)
        timeouts.append(kw.get("timeout"))
        if isinstance(output, Exception):
            raise output
        return output

    values = dict(runner=runner, proc=proc())
    values.update(overrides)
    return restart_needed.collect(**values), calls


class CollectTest(unittest.TestCase):
    def test_parses_batch_output(self):
        result, calls = collect()
        self.assertEqual(calls, [["needrestart", "-b", "-r", "l"]])
        self.assertEqual(result.data["needrestart"], {
            "kernel_current": "6.8.0-147-generic", "kernel_expected": "6.8.0-150-generic",
            "kernel_status": 3, "services": ["apache2.service", "ssh.service"],
        })
        self.assertEqual(result.source_errors, {})

    def test_runs_with_25s_timeout(self):
        timeouts.clear()
        collect()
        self.assertEqual(timeouts, [25])

    def test_no_service_to_restart_is_empty_list(self):
        result, _ = collect("NEEDRESTART-VER: 3.6\nNEEDRESTART-KSTA: 1\n")
        self.assertEqual(result.data["needrestart"]["services"], [])
        self.assertIsNone(result.data["needrestart"]["kernel_current"])

    def test_needrestart_absent_masters_still_filled(self):
        result, _ = collect(util.CommandError("command not found: needrestart"))
        self.assertIsNone(result.data["needrestart"])
        self.assertEqual(result.source_errors["needrestart"], "not_installed")
        self.assertEqual([m["pid"] for m in result.data["masters"]], [100, 300, 200])

    def test_masters_cover_apache_and_php_fpm(self):
        result, _ = collect()
        self.assertEqual([(m["comm"], m["pid"]) for m in result.data["masters"]],
                         [("apache2", 100), ("php-fpm8.2", 300), ("php-fpm8.3", 200)])
