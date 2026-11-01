import json
import threading
import unittest

from discover_agent import sections, util
from discover_agent.sections import Collected

NOW = "2026-11-01T08:00:00Z"


def now():
    return NOW


def ok(data=None, source_errors=None):
    return lambda: Collected([1, 2] if data is None else data, source_errors or {})


def not_installed():
    raise util.NotInstalled("debsums")


def crash():
    raise util.CollectorError("lecture impossible")


class EnvelopeTest(unittest.TestCase):
    def run_one(self, collect, name="packages", timeouts=None, due=()):
        return sections.run_all(set(due), {name: collect}, timeouts, now)[name]

    def test_envelope_success(self):
        data = {"b": 1, "a": [1, 2]}
        env = self.run_one(ok(data))
        self.assertIsNone(env["error"])
        self.assertEqual(env["source_errors"], {})
        self.assertEqual(env["data"], data)
        self.assertEqual(env["collected_at"], NOW)
        self.assertEqual(env["sha256"], sections.canonical_sha256(data))

    def test_source_errors_that_cannot_be_encoded_fail_the_section(self):
        env = self.run_one(ok({"a": 1}, {"src": "bad \ud800 text"}))
        self.assertIsNotNone(env["error"])
        self.assertIsNone(env["data"])
        self.assertIsNone(env["sha256"])

    def test_source_errors_are_carried(self):
        env = self.run_one(ok({"nft": None}, {"nft": "not_installed"}))
        self.assertEqual(env["source_errors"], {"nft": "not_installed"})
        self.assertIsNone(env["error"])

    def test_sha_is_stable_across_key_order(self):
        self.assertEqual(
            sections.canonical_sha256({"a": 1, "b": {"x": 1, "y": 2}}),
            sections.canonical_sha256({"b": {"y": 2, "x": 1}, "a": 1}),
        )
        self.assertNotEqual(sections.canonical_sha256({"a": 1}), sections.canonical_sha256({"a": 2}))

    def test_sha_is_over_compact_utf8_json(self):
        import hashlib
        self.assertEqual(
            sections.canonical_sha256({"k": "é"}),
            hashlib.sha256('{"k":"é"}'.encode("utf-8")).hexdigest(),
        )

    def test_exception_gives_message_and_null_data(self):
        env = self.run_one(crash)
        self.assertEqual(env["error"], "lecture impossible")
        self.assertIsNone(env["data"])
        self.assertIsNone(env["sha256"])
        self.assertEqual(env["collected_at"], NOW)

    def test_unexpected_exception_names_its_type(self):
        def bad():
            raise KeyError("x")
        self.assertEqual(self.run_one(bad)["error"], "KeyError: 'x'")

    def test_unserialisable_data_fails_only_its_section(self):
        for bad in ({1, 2}, b"raw", object()):
            envelopes = sections.run_all(
                set(), {"packages": lambda bad=bad: Collected(bad), "apt_sources": ok(["deb"])}, None, now)
            self.assertIsNotNone(envelopes["packages"]["error"], bad)
            self.assertIsNone(envelopes["packages"]["data"])
            self.assertIsNone(envelopes["packages"]["sha256"])
            self.assertEqual(envelopes["apt_sources"]["data"], ["deb"])

    def test_return_that_is_not_collected_fails_only_its_section(self):
        envelopes = sections.run_all(
            set(), {"packages": lambda: {"a": 1}, "apt_sources": ok(["deb"])}, None, now)
        self.assertIn("Collected", envelopes["packages"]["error"])
        self.assertIsNone(envelopes["packages"]["data"])
        self.assertEqual(envelopes["apt_sources"]["data"], ["deb"])

    def test_empty_exception_message_falls_back_to_the_type_name(self):
        def silent():
            raise util.CollectorError()
        self.assertEqual(self.run_one(silent)["error"], "CollectorError")

    def test_not_installed(self):
        env = self.run_one(not_installed)
        self.assertEqual(env["error"], "not_installed")
        self.assertIsNone(env["data"])
        self.assertIsNone(env["sha256"])

    def test_timeout_does_not_delay_the_next_section(self):
        release = threading.Event()
        self.addCleanup(release.set)

        def blocking():
            release.wait(5)
            return Collected([])

        envelopes = sections.run_all(
            set(), {"packages": blocking, "apt_sources": ok(["deb"])},
            {"packages": 0.05, "apt_sources": 1}, now,
        )
        self.assertEqual(envelopes["packages"]["error"], "timeout")
        self.assertIsNone(envelopes["packages"]["data"])
        self.assertEqual(envelopes["apt_sources"]["data"], ["deb"])
        self.assertEqual(list(envelopes), ["packages", "apt_sources"])

    def test_default_timeouts(self):
        self.assertEqual(sections.timeout_for("packages"), 30)
        self.assertEqual(sections.timeout_for("integrity"), 300)
        self.assertEqual(sections.timeout_for("code_patterns"), 300)

    def test_not_due_is_skipped(self):
        registry = {"integrity": ok(), "code_patterns": ok()}
        envelopes = sections.run_all({"code_patterns"}, registry, None, now)
        self.assertEqual(envelopes["integrity"], {"error": "skipped_not_due"})
        self.assertIsNone(envelopes["code_patterns"]["error"])

    def test_weekly_section_that_is_due_runs(self):
        self.assertEqual(
            sections.run_all({"integrity"}, {"integrity": ok(["f"])}, None, now)["integrity"]["data"], ["f"]
        )

    def test_unregistered_names_are_omitted_and_order_follows_the_spec(self):
        registry = {"docker": ok(), "packages": ok()}
        self.assertEqual(list(sections.run_all(set(), registry, None, now)), ["packages", "docker"])

    def test_section_names_are_the_fourteen_of_the_spec(self):
        self.assertEqual(len(sections.SECTION_NAMES), 14)
        self.assertEqual(sections.SECTION_NAMES[0], "packages")
        self.assertEqual(sections.SECTION_NAMES[-2:], sections.WEEKLY)

    def test_read_source(self):
        errors = {}
        self.assertEqual(sections.read_source(errors, "a", lambda: 5), 5)
        self.assertIsNone(sections.read_source(errors, "nft", not_installed))
        self.assertIsNone(sections.read_source(errors, "ss", crash))
        self.assertEqual(errors, {"nft": "not_installed", "ss": "lecture impossible"})


class CapsTest(unittest.TestCase):
    def envelope(self, size):
        data = "x" * size
        return {"collected_at": NOW, "error": None, "source_errors": {}, "data": data,
                "sha256": sections.canonical_sha256(data)}

    def test_section_over_cap_is_too_large(self):
        envelopes = {"packages": self.envelope(100), "apt_sources": self.envelope(10)}
        sections.enforce_caps(envelopes, section_max=50, total_max=1000)
        self.assertEqual(envelopes["packages"]["error"], "too_large")
        self.assertIsNone(envelopes["packages"]["data"])
        self.assertIsNone(envelopes["packages"]["sha256"])
        self.assertIsNone(envelopes["apt_sources"]["error"])

    def test_total_cap_drops_largest_first(self):
        envelopes = {"a": self.envelope(40), "b": self.envelope(30), "c": self.envelope(20)}
        sections.enforce_caps(envelopes, section_max=100, total_max=60)
        self.assertEqual(envelopes["a"]["error"], "too_large")
        self.assertIsNone(envelopes["b"]["error"])
        self.assertIsNone(envelopes["c"]["error"])

    def test_skipped_and_failed_envelopes_are_untouched(self):
        envelopes = {"integrity": {"error": "skipped_not_due"},
                     "packages": {"collected_at": NOW, "error": "timeout", "source_errors": {},
                                  "data": None, "sha256": None}}
        sections.enforce_caps(envelopes, section_max=1, total_max=1)
        self.assertEqual(envelopes["integrity"], {"error": "skipped_not_due"})
        self.assertEqual(envelopes["packages"]["error"], "timeout")

    def test_default_caps(self):
        self.assertEqual(sections.SECTION_MAX, 4 * 1024 * 1024)
        self.assertEqual(sections.TOTAL_MAX, 16 * 1024 * 1024)


class WeeklyTest(unittest.TestCase):
    def envelope(self, error=None):
        return {"collected_at": NOW, "error": error, "source_errors": {},
                "data": None if error else [], "sha256": None if error else "h"}

    def test_due_weekly(self):
        self.assertEqual(sections.due_weekly({}, 7, NOW), {"integrity", "code_patterns"})
        state = {"weekly": {"integrity": {"next_run": "2026-11-02T08:00:00Z"},
                            "code_patterns": {"next_run": "2026-10-31T08:00:00Z"}}}
        self.assertEqual(sections.due_weekly(state, 7, NOW), {"code_patterns"})

    def test_due_weekly_ignores_a_garbled_date(self):
        state = {"weekly": {"integrity": {"next_run": "never"}}}
        self.assertIn("integrity", sections.due_weekly(state, 7, NOW))

    def test_due_weekly_survives_a_corrupted_state(self):
        both = {"integrity", "code_patterns"}
        for weekly in ("oops", ["x"], None, {"integrity": "oops", "code_patterns": ["x"]},
                       {"integrity": {"next_run": 5}}):
            self.assertEqual(sections.due_weekly({"weekly": weekly}, 7, NOW), both, weekly)

    def test_record_weekly_repairs_a_corrupted_state(self):
        state = {"weekly": "oops"}
        sections.record_weekly(state, {"integrity": self.envelope()}, 7, NOW)
        self.assertEqual(state["weekly"], {"integrity": {"next_run": "2026-11-08T08:00:00Z"}})

    def test_record_weekly_success_waits_interval(self):
        state = {}
        sections.record_weekly(state, {"integrity": self.envelope()}, 7, NOW)
        self.assertEqual(state["weekly"]["integrity"], {"next_run": "2026-11-08T08:00:00Z"})
        self.assertNotIn("code_patterns", state["weekly"])

    def test_record_weekly_not_installed_waits_interval(self):
        state = {}
        sections.record_weekly(state, {"integrity": self.envelope("not_installed")}, 3, NOW)
        self.assertEqual(state["weekly"]["integrity"]["next_run"], "2026-11-04T08:00:00Z")

    def test_record_weekly_timeout_waits_24h(self):
        for error in ("timeout", "too_large", "boom"):
            state = {}
            sections.record_weekly(state, {"code_patterns": self.envelope(error)}, 7, NOW)
            self.assertEqual(state["weekly"]["code_patterns"]["next_run"], "2026-11-02T08:00:00Z", error)

    def test_record_weekly_leaves_skipped_and_other_sections_alone(self):
        state = {"weekly": {"integrity": {"next_run": "2026-11-05T08:00:00Z"}}}
        sections.record_weekly(
            state, {"integrity": {"error": "skipped_not_due"}, "packages": self.envelope()}, 7, NOW)
        self.assertEqual(state["weekly"], {"integrity": {"next_run": "2026-11-05T08:00:00Z"}})

    def test_state_is_json_serialisable(self):
        state = {}
        sections.record_weekly(state, {"integrity": self.envelope()}, 7, NOW)
        json.dumps(state)


class DefaultsTest(unittest.TestCase):
    def test_defaults_from_the_spec(self):
        from discover_agent.sections import defaults
        self.assertEqual(len(defaults.SYSCTL), 9)
        self.assertIn("net.mptcp.enabled", defaults.SYSCTL)
        self.assertEqual(len(defaults.PHP_INI), 10)
        self.assertIn("session.serialize_handler", defaults.PHP_INI)
        self.assertEqual(defaults.MITIGATION_SERVICES, ("cve98432-filter.service",))
        ids = [p["id"] for p in defaults.CODE_PATTERNS]
        self.assertEqual(ids, ["serialize-handler-iniset", "serialize-handler-htaccess"])
        for pattern in defaults.CODE_PATTERNS:
            self.assertEqual(set(pattern), {"id", "regex", "globs"})


class DescribeTest(unittest.TestCase):
    def test_long_error_is_truncated(self):
        text = sections.describe(util.CollectorError("x" * 10000))
        self.assertEqual(len(text), 4000)

    def test_short_error_is_unchanged(self):
        self.assertEqual(sections.describe(util.CollectorError("court")), "court")

    def test_credentials_in_error_are_masked(self):
        exc = util.CommandError("apt-cache exited 100: E: Failed to fetch https://user:hunter2@repo.example.com/x")
        self.assertNotIn("hunter2", sections.describe(exc))

    def test_read_source_masks_credentials(self):
        errors = {}

        def fail():
            raise util.CommandError("pro exited 1: https://user:hunter2@h/x")

        sections.read_source(errors, "pro", fail)
        self.assertNotIn("hunter2", errors["pro"])


if __name__ == "__main__":
    unittest.main()
