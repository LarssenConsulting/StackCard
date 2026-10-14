import json
import shlex
import os
import ssl
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

from discover_agent import transport


class Recorder(BaseHTTPRequestHandler):
    requests = []

    def do_POST(self):
        body = self.rfile.read(int(self.headers["Content-Length"]))
        Recorder.requests.append({
            "path": self.path,
            "auth": self.headers.get("Authorization"),
            "type": self.headers.get("Content-Type"),
            "body": json.loads(body),
        })
        reply = json.dumps({"status": "ok"}).encode()
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(reply)))
        self.end_headers()
        self.wfile.write(reply)

    def log_message(self, *args):
        pass


def sh(cmd):
    return subprocess.run(cmd, shell=True, check=True, capture_output=True).stdout


class TransportTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.tmp = tempfile.TemporaryDirectory()
        cls.cert = os.path.join(cls.tmp.name, "cert.pem")
        key = os.path.join(cls.tmp.name, "key.pem")
        q_key, q_cert = shlex.quote(key), shlex.quote(cls.cert)
        sh(
            f"openssl req -x509 -newkey rsa:2048 -nodes -keyout {q_key} -out {q_cert} "
            "-days 1 -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1"
        )
        # Reference pin computed by openssl, to cross-check our DER parsing.
        cls.pin = sh(
            f"openssl x509 -in {q_cert} -pubkey -noout | openssl pkey -pubin -outform der "
            "| openssl dgst -sha256 -binary | base64"
        ).decode().strip()

        cls.server = ThreadingHTTPServer(("127.0.0.1", 0), Recorder)
        # Rejected handshakes are expected here; keep the test output clean.
        cls.server.handle_error = lambda request, address: None
        context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        context.load_cert_chain(cls.cert, key)
        cls.server.socket = context.wrap_socket(cls.server.socket, server_side=True)
        cls.url = f"https://localhost:{cls.server.server_address[1]}"
        threading.Thread(target=cls.server.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.server.shutdown()
        cls.tmp.cleanup()

    def setUp(self):
        Recorder.requests = []

    def test_plain_http_is_refused(self):
        with self.assertRaises(ValueError):
            transport.post_json("http://central.example.com/api", {})

    def test_untrusted_certificate_is_rejected(self):
        with self.assertRaises(ssl.SSLError):
            transport.post_json(self.url + "/api/v1/inventory", {"a": 1}, token="t")
        self.assertEqual(Recorder.requests, [])

    def test_posts_json_with_bearer_token_when_ca_is_trusted(self):
        status, body = transport.post_json(
            self.url + "/api/v1/inventory", {"a": 1}, token="tok", ca_file=self.cert
        )
        self.assertEqual((status, body), (200, {"status": "ok"}))
        self.assertEqual(
            Recorder.requests,
            [{"path": "/api/v1/inventory", "auth": "Bearer tok",
              "type": "application/json", "body": {"a": 1}}],
        )

    def test_matching_pin_is_accepted(self):
        status, _ = transport.post_json(
            self.url + "/x", {}, ca_file=self.cert, pin_sha256=self.pin
        )
        self.assertEqual(status, 200)
        status, _ = transport.post_json(
            self.url + "/x", {}, ca_file=self.cert, pin_sha256="sha256/" + self.pin
        )
        self.assertEqual(status, 200)

    def test_wrong_pin_aborts_before_sending(self):
        with self.assertRaises(transport.PinMismatch):
            transport.post_json(
                self.url + "/x", {"secret": 1}, token="tok", ca_file=self.cert,
                pin_sha256="AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=",
            )
        self.assertEqual(Recorder.requests, [])


if __name__ == "__main__":
    unittest.main()
