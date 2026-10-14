"""HTTPS client for the central API.

Certificate verification is always on and cannot be turned off. Optionally the
server public key is pinned: the SHA-256 of its SubjectPublicKeyInfo, base64,
the same value as:
  openssl x509 -in cert.pem -pubkey -noout | openssl pkey -pubin -outform der \
    | openssl dgst -sha256 -binary | base64
"""

import base64
import hashlib
import http.client
import json
import ssl
from urllib.parse import urlsplit

from discover_agent import __version__

DEFAULT_TIMEOUT = 30


class PinMismatch(ssl.SSLError):
    """The server presented a valid certificate whose public key is not the pinned one."""


def _tlv(data, pos):
    """Read one DER element at `pos`: return (tag, value_start, value_end)."""
    tag, first = data[pos], data[pos + 1]
    if first < 0x80:
        return tag, pos + 2, pos + 2 + first
    size = first & 0x7F
    length = int.from_bytes(data[pos + 2:pos + 2 + size], "big")
    start = pos + 2 + size
    return tag, start, start + length


def spki_from_certificate(der):
    _, cert_start, _ = _tlv(der, 0)          # Certificate
    _, pos, _ = _tlv(der, cert_start)         # tbsCertificate
    tag, _, end = _tlv(der, pos)
    if tag == 0xA0:                           # optional explicit version
        pos = end
    for _ in range(5):                        # serial, signature, issuer, validity, subject
        _, _, pos = _tlv(der, pos)
    _, _, end = _tlv(der, pos)
    return der[pos:end]


def spki_sha256(der):
    return base64.b64encode(hashlib.sha256(spki_from_certificate(der)).digest()).decode()


def _normalize_pin(pin):
    return pin.strip().removeprefix("sha256/")


def _context(ca_file):
    context = ssl.create_default_context(cafile=ca_file)
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    return context


def post_json(url, payload, token=None, ca_file=None, pin_sha256=None, timeout=DEFAULT_TIMEOUT):
    parts = urlsplit(url)
    if parts.scheme != "https":
        raise ValueError(f"refusing non-HTTPS URL: {url}")
    connection = http.client.HTTPSConnection(
        parts.hostname, parts.port or 443, context=_context(ca_file), timeout=timeout
    )
    try:
        connection.connect()
        if pin_sha256:
            presented = spki_sha256(connection.sock.getpeercert(binary_form=True))
            if presented != _normalize_pin(pin_sha256):
                raise PinMismatch(f"server key {presented} does not match the pinned key")
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
            "User-Agent": f"discover-agent/{__version__}",
        }
        if token:
            headers["Authorization"] = f"Bearer {token}"
        path = (parts.path or "/") + (f"?{parts.query}" if parts.query else "")
        connection.request("POST", path, body=json.dumps(payload).encode(), headers=headers)
        response = connection.getresponse()
        raw = response.read()
        try:
            body = json.loads(raw) if raw else {}
        except ValueError:
            body = {"raw": raw[:500].decode("utf-8", errors="replace")}
        return response.status, body
    finally:
        connection.close()
