import json
import os
import re
import tempfile
import time
import unittest

from discover_agent import util
from discover_agent.sections import REGISTRY, code_patterns
from discover_agent.sections.core import canonical_sha256
from discover_agent.sections.defaults import CODE_PATTERNS

INISET = "<?php ini_set('session.serialize_handler', 'php_serialize'); // CANARY\n"


def fake_vhosts(*roots):
    def loader(runner=None, reader=None):
        return [{"document_root": r} for r in roots]

    return loader


class CodePatternsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.tmp_real = os.path.realpath(self.tmp.name)
        self.root = os.path.join(self.tmp_real, "www")
        os.mkdir(self.root)

    def write(self, rel, content, mode="w"):
        path = os.path.join(self.root, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, mode) as fh:
            fh.write(content)
        return path

    def collect(self, *roots, **kwargs):
        kwargs.setdefault("vhosts", fake_vhosts(*(roots or (self.root,))))
        return code_patterns.collect(**kwargs)

    def test_registered_and_defaults_compile(self):
        self.assertIs(REGISTRY["code_patterns"], code_patterns.collect)
        for pattern in CODE_PATTERNS:
            re.compile(pattern["regex"])

    def test_match_gives_path_line_pattern_only(self):
        self.write("a/index.php", "<?php\n" + INISET)
        result = self.collect()
        self.assertEqual(
            result.data["matches"],
            [{"path": os.path.join(self.root, "a/index.php"), "line": 2, "pattern": "serialize-handler-iniset"}],
        )
        self.assertNotIn("CANARY", json.dumps(result.data))
        self.assertNotIn("CANARY", json.dumps(result.source_errors))
        self.assertFalse(result.data["truncated"])

    def test_globs_are_respected(self):
        self.write("a.txt", INISET)
        self.write("b.php", "serialize_handler = x\n")
        self.write(".htaccess", "php_value session.serialize_handler php\n")
        self.write("sub/.user.ini", "session.serialize_handler=php\n")
        matches = self.collect().data["matches"]
        found = sorted((os.path.relpath(m["path"], self.root), m["pattern"]) for m in matches)
        self.assertEqual(found, [
            (".htaccess", "serialize-handler-htaccess"),
            ("sub/.user.ini", "serialize-handler-htaccess"),
        ])

    def test_apache_absent_is_not_installed(self):
        def runner(cmd, **kwargs):
            raise util.CommandError("command not found: apache2ctl")

        with self.assertRaises(util.NotInstalled):
            code_patterns.collect(runner=runner)

    def test_roots_deduplicated_and_missing_listed(self):
        self.write("a.php", INISET)
        gone = os.path.join(self.tmp_real, "gone")
        result = self.collect(self.root, self.root, gone)
        self.assertEqual(len(result.data["matches"]), 1)
        self.assertEqual(result.data["missing_roots"], [gone])
        self.assertEqual(result.source_errors, {})

    def test_symlinked_docroot_is_resolved_once(self):
        self.write("a.php", INISET)
        link = os.path.join(self.tmp_real, "current")
        os.symlink(self.root, link)
        matches = self.collect(link).data["matches"]
        self.assertEqual(len(matches), 1)

    def test_hostile_webroot(self):
        self.write("ok.php", INISET)
        os.mkfifo(os.path.join(self.root, "pipe.php"))
        os.symlink(self.root, os.path.join(self.root, "loop"))
        os.symlink(os.path.join(self.root, "ok.php"), os.path.join(self.root, "link.php"))
        self.write("bin.php", b"\xff\xfe\x00\x01" * 100 + b"ini_set('session.serialize_handler'\n", "wb")
        self.write("big.php", "x" * (2 * 1024 * 1024))
        self.write("long.php", "y" * (5 * 1024 * 1024))
        gone = os.path.join(self.tmp_real, "gone")
        started = time.monotonic()
        result = self.collect(self.root, gone)
        self.assertLess(time.monotonic() - started, 20)
        paths = sorted(os.path.relpath(m["path"], self.root) for m in result.data["matches"])
        self.assertEqual(paths, ["bin.php", "ok.php"])
        self.assertEqual(result.data["skipped_files"], 3)  # fifo, 2 MiB, 5 MiB
        self.assertEqual(result.data["missing_roots"], [gone])

    def test_truncated_after_10000(self):
        self.write("a.php", INISET * 10005)
        data = self.collect().data
        self.assertEqual(len(data["matches"]), 10000)
        self.assertTrue(data["truncated"])
        self.assertEqual(data["reason"], "max_matches")

    def test_deadline_stops_cleanly(self):
        self.write("a.php", INISET)
        self.write("b.php", INISET)
        ticks = iter([0, 0, 1000, 1000, 1000, 1000, 1000])
        data = self.collect(clock=lambda: next(ticks, 1000)).data
        self.assertTrue(data["truncated"])
        self.assertEqual(data["reason"], "deadline")

    def test_catastrophic_line_is_bounded(self):
        self.write("huge.php", "a" * (1024 * 1024) + "\n")  # over the 1 MiB read limit
        self.write("long.php", "a" * (100 * 1024) + "\n" + INISET)
        started = time.monotonic()
        result = self.collect()
        self.assertLess(time.monotonic() - started, 5)
        self.assertEqual(result.data["skipped_files"], 2)
        # a file holding a line that long is not analysed at all
        self.assertEqual(result.data["matches"], [])

    def test_control_characters_in_path_are_skipped(self):
        self.write("bad\nname.php", INISET)
        self.write("good.php", INISET)
        data = self.collect().data
        self.assertEqual([os.path.basename(m["path"]) for m in data["matches"]], ["good.php"])
        self.assertEqual(data["skipped_files"], 1)

    def test_encodable_path_check(self):
        self.assertTrue(code_patterns._encodable("/var/www/caf\u00e9.php"))
        self.assertFalse(code_patterns._encodable("/var/www/x\udcff.php"))

    def test_non_utf8_filename_is_skipped_not_fatal(self):
        self.write("good.php", INISET)
        bad = os.path.join(os.fsencode(self.root), b"x\xff.php")
        try:
            with open(bad, "w") as fh:
                fh.write(INISET)
        except OSError:
            self.skipTest("file system rejects non-UTF-8 names")
        result = self.collect()
        self.assertEqual([os.path.basename(m["path"]) for m in result.data["matches"]], ["good.php"])
        self.assertEqual(result.data["skipped_files"], 1)
        canonical_sha256(result.data)  # must serialise

    def test_non_utf8_missing_root_is_not_emitted(self):
        result = self.collect(self.root, "/nonexistent/x\udcff")
        self.assertEqual(result.data["missing_roots"], [])
        self.assertEqual(result.data["skipped_files"], 1)
        canonical_sha256(result.data)

    def test_pattern_spanning_lines_is_found(self):
        self.write("a.php", "<?php\nini_set(\n   'session.serialize_handler', 'x');\nini_set('session.serialize_handler', 1);\n")
        lines = [m["line"] for m in self.collect().data["matches"]]
        self.assertEqual(lines, [2, 4])

    def test_nested_roots_report_once(self):
        self.write("site/a.php", INISET)
        result = self.collect(self.root, os.path.join(self.root, "site"))
        self.assertEqual(len(result.data["matches"]), 1)

    def test_unreadable_directory_is_counted(self):
        self.write("ok.php", INISET)
        locked = os.path.join(self.root, "locked")
        os.mkdir(locked)
        os.chmod(locked, 0)
        self.addCleanup(os.chmod, locked, 0o755)
        if os.access(locked, os.R_OK):
            self.skipTest("running as root")
        result = self.collect()
        self.assertEqual(result.data["skipped_files"], 1)
        self.assertEqual(len(result.data["matches"]), 1)

    def test_matches_sorted(self):
        self.write("b.php", INISET)
        self.write("a.php", INISET + INISET)
        keys = [(m["path"], m["line"]) for m in self.collect().data["matches"]]
        self.assertEqual(keys, sorted(keys))


if __name__ == "__main__":
    unittest.main()
