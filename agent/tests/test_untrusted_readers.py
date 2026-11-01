"""Paths from `Include` and `apache2ctl -S` can sit under a webroot: a real FIFO or a link to a device."""

import inspect
import os
import tempfile
import threading
import unittest

from discover_agent import util
from discover_agent.collectors import apache as ctl
from discover_agent.sections import apache as section_apache
from discover_agent.sections import code_patterns, php


def within(seconds, fn):
    """Run `fn` in a daemon thread: a blocked open() fails the test instead of hanging the suite."""
    box = {}
    thread = threading.Thread(target=lambda: box.update(result=fn()), daemon=True)
    thread.start()
    thread.join(seconds)
    if thread.is_alive():
        raise AssertionError("the reader blocked")
    return box["result"]


class DefaultsTest(unittest.TestCase):
    def test_readers_of_apache_paths_default_to_the_safe_one(self):
        for fn in (ctl.load_vhosts, php.collect, section_apache.collect, code_patterns.collect):
            with self.subTest(fn=fn.__module__):
                self.assertIs(inspect.signature(fn).parameters["reader"].default, util.read_regular_file)


class HostileFilesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.fifo = os.path.join(self.tmp.name, "pipe.conf")
        os.mkfifo(self.fifo)
        self.zero = os.path.join(self.tmp.name, "zero.conf")
        os.symlink("/dev/zero", self.zero)
        self.good = os.path.join(self.tmp.name, "good.conf")
        with open(self.good, "w") as fh:
            fh.write("<VirtualHost *:80>\nServerName good.test\nDocumentRoot /srv/good\n</VirtualHost>\n")

    def ctl_output(self):
        return (
            "VirtualHost configuration:\n"
            f"*:80 fifo.test ({self.fifo}:1)\n"
            f"*:80 zero.test ({self.zero}:1)\n"
            f"*:80 good.test ({self.good}:1)\n"
        )

    def test_load_vhosts_survives_a_fifo_and_a_device_link(self):
        vhosts = within(5, lambda: ctl.load_vhosts(runner=lambda cmd: self.ctl_output()))
        self.assertEqual([v["server_name"] for v in vhosts], ["good.test"])

    def test_apache_section_reports_them_unreadable(self):
        errors = {}
        vhosts = within(5, lambda: section_apache._vhosts(self.ctl_output(), util.read_regular_file, errors))
        self.assertEqual([v["file"] for v in vhosts if v["server_name"] == "good.test"], [self.good])
        self.assertEqual(errors[f"read {self.fifo}"], "unreadable")
        self.assertEqual(errors[f"read {self.zero}"], "unreadable")

    def test_php_config_walk_survives_an_include_of_them(self):
        main = os.path.join(self.tmp.name, "apache2.conf")
        with open(main, "w") as fh:
            fh.write(f"Include {self.fifo}\nInclude {self.zero}\nIncludeOptional {self.fifo}\n")
        config = php.ApacheConfig(util.read_regular_file, lambda path: None, set())
        within(5, lambda: config.walk_file(main, [], 0))

    def test_a_oversized_config_is_refused_not_read(self):
        big = os.path.join(self.tmp.name, "big.conf")
        with open(big, "wb") as fh:
            fh.truncate(2 * 1024 * 1024)
        self.assertIsNone(util.read_regular_file(big))


if __name__ == "__main__":
    unittest.main()
