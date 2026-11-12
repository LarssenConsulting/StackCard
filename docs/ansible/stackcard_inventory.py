#!/usr/bin/env python3
"""Ansible dynamic inventory backed by the StackCard read API (standard library only).

Environment:
  STACKCARD_URL      base URL of the central, HTTPS only (e.g. https://stackcard.example.com)
  STACKCARD_TOKEN    read API token (ability inventory:read)
  STACKCARD_CA_FILE  optional CA bundle for an internal certificate authority
"""
import argparse
import http.client
import json
import os
import ssl
import sys
import urllib.error
import urllib.request

PATH = "/api/v1/inventory/ansible"
TIMEOUT = 30


class InventoryError(Exception):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Never follow a redirect: it would re-send the bearer token, possibly over HTTP or to another host."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def fetch_inventory(url, token, ca_file=None, opener=None):
    """Return the parsed inventory. `opener` (an object with .open(request, timeout=)) is injectable."""
    if not url.lower().startswith("https://"):
        raise InventoryError("STACKCARD_URL doit commencer par https:// (HTTPS obligatoire).")
    if opener is None:
        try:
            context = ssl.create_default_context(cafile=ca_file or None)
        except (OSError, ssl.SSLError) as exc:
            raise InventoryError(f"STACKCARD_CA_FILE inutilisable : {exc}") from exc
        opener = urllib.request.build_opener(urllib.request.HTTPSHandler(context=context), NoRedirect)
    request = urllib.request.Request(
        url.rstrip("/") + PATH,
        headers={"Authorization": "Bearer " + token, "Accept": "application/json"},
    )
    try:
        with opener.open(request, timeout=TIMEOUT) as response:
            status = response.status
            body = response.read()
    except urllib.error.HTTPError as exc:
        raise InventoryError(f"Réponse HTTP {exc.code} du central.") from exc
    except (urllib.error.URLError, http.client.HTTPException, OSError) as exc:
        raise InventoryError(f"Central injoignable : {exc}") from exc
    if status != 200:
        raise InventoryError(f"Réponse HTTP {status} du central.")
    try:
        inventory = json.loads(body)
    except ValueError as exc:
        raise InventoryError("Réponse du central : JSON invalide.") from exc
    if not isinstance(inventory, dict) or "_meta" not in inventory:
        raise InventoryError("Réponse du central : ce n'est pas un inventaire Ansible (clé _meta absente).")
    return inventory


def main(argv=None, opener=None):
    parser = argparse.ArgumentParser(description="Inventaire Ansible dynamique StackCard")
    group = parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--list", action="store_true", help="imprime tout l'inventaire")
    group.add_argument("--host", metavar="SLUG", help="imprime {} (les hostvars sont dans _meta)")
    args = parser.parse_args(argv)

    if args.host is not None:
        print("{}")
        return 0

    url = os.environ.get("STACKCARD_URL", "").strip()
    token = os.environ.get("STACKCARD_TOKEN", "").strip()
    for name, value in (("STACKCARD_URL", url), ("STACKCARD_TOKEN", token)):
        if not value:
            print(f"Variable d'environnement manquante : {name}", file=sys.stderr)
            return 1
    try:
        inventory = fetch_inventory(url, token, os.environ.get("STACKCARD_CA_FILE"), opener)
    except InventoryError as exc:
        print(f"stackcard_inventory : {exc}", file=sys.stderr)
        return 1
    print(json.dumps(inventory, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    sys.exit(main())
