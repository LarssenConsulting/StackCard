import io
import json
import os
import unittest
from contextlib import redirect_stderr, redirect_stdout
from unittest import mock

import stackcard_inventory as inv

INVENTORY = {"_meta": {"hostvars": {"web01": {"ansible_host": "203.0.113.5"}}}, "all": {"hosts": ["web01"], "children": []}}


class FakeResponse:
    def __init__(self, status=200, body=b""):
        self.status = status
        self._body = body

    def read(self):
        return self._body

    def __enter__(self):
        return self

    def __exit__(self, *exc):
        return False


class FakeOpener:
    def __init__(self, response):
        self.response = response
        self.requests = []

    def open(self, request, timeout=None):
        self.requests.append(request)
        if isinstance(self.response, Exception):
            raise self.response
        return self.response


ENV = {"STACKCARD_URL": "https://stackcard.example.com/", "STACKCARD_TOKEN": "tok"}


def run(argv, env=ENV, opener=None):
    out, err = io.StringIO(), io.StringIO()
    with mock.patch.dict(os.environ, env, clear=True), redirect_stdout(out), redirect_stderr(err):
        code = inv.main(argv, opener=opener)
    return code, out.getvalue(), err.getvalue()


class InventoryTest(unittest.TestCase):
    def test_list_prints_the_json_received(self):
        opener = FakeOpener(FakeResponse(200, json.dumps(INVENTORY).encode()))
        code, out, _ = run(["--list"], opener=opener)
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(out), INVENTORY)
        request = opener.requests[0]
        self.assertEqual(request.full_url, "https://stackcard.example.com/api/v1/inventory/ansible")
        self.assertEqual(request.get_header("Authorization"), "Bearer tok")

    def test_host_prints_an_empty_object_without_a_request(self):
        opener = FakeOpener(FakeResponse())
        code, out, _ = run(["--host", "web01"], opener=opener)
        self.assertEqual((code, json.loads(out)), (0, {}))
        self.assertEqual(opener.requests, [])

    def test_missing_token_fails(self):
        code, out, err = run(["--list"], env={"STACKCARD_URL": "https://x.example"}, opener=FakeOpener(FakeResponse()))
        self.assertEqual(code, 1)
        self.assertIn("STACKCARD_TOKEN", err)
        self.assertEqual(out, "")

    def test_missing_url_fails(self):
        code, _, err = run(["--list"], env={"STACKCARD_TOKEN": "t"}, opener=FakeOpener(FakeResponse()))
        self.assertEqual(code, 1)
        self.assertIn("STACKCARD_URL", err)

    def test_http_url_is_refused(self):
        opener = FakeOpener(FakeResponse())
        code, _, err = run(["--list"], env={"STACKCARD_URL": "http://stackcard.example.com", "STACKCARD_TOKEN": "t"}, opener=opener)
        self.assertEqual(code, 1)
        self.assertIn("HTTPS", err)
        self.assertEqual(opener.requests, [])

    def test_non_200_fails(self):
        code, out, err = run(["--list"], opener=FakeOpener(FakeResponse(403, b"{}")))
        self.assertEqual(code, 1)
        self.assertIn("403", err)
        self.assertEqual(out, "")

    def test_network_error_fails(self):
        code, _, err = run(["--list"], opener=FakeOpener(OSError("boom")))
        self.assertEqual(code, 1)
        self.assertIn("boom", err)

    def test_invalid_json_fails(self):
        code, _, err = run(["--list"], opener=FakeOpener(FakeResponse(200, b"<html>")))
        self.assertEqual(code, 1)
        self.assertIn("JSON", err)

    def test_non_inventory_json_fails(self):
        for body in (b"[]", b'{"foo": 1}', b'"x"'):
            code, out, err = run(["--list"], opener=FakeOpener(FakeResponse(200, body)))
            self.assertEqual(code, 1)
            self.assertIn("_meta", err)
            self.assertEqual(out, "")

    def test_bad_ca_file_fails_cleanly(self):
        env = dict(ENV, STACKCARD_CA_FILE="/nonexistent/ca.pem")
        code, out, err = run(["--list"], env=env)
        self.assertEqual(code, 1)
        self.assertIn("STACKCARD_CA_FILE", err)
        self.assertEqual(out, "")

    def test_http_exception_fails_cleanly(self):
        import http.client
        code, _, err = run(["--list"], opener=FakeOpener(http.client.RemoteDisconnected("closed")))
        self.assertEqual(code, 1)
        self.assertIn("closed", err)

    def test_redirects_are_not_followed(self):
        import urllib.request
        handler = inv.NoRedirect()
        req = urllib.request.Request("https://a.example/x", headers={"Authorization": "Bearer t"})
        self.assertIsNone(handler.redirect_request(req, None, 302, "Found", {}, "http://evil.example/"))


if __name__ == "__main__":
    unittest.main()
