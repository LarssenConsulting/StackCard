"""Assert that `discover-agent collect` inside the integration image saw everything."""

import json
import sys

SECRETS = ("S3cret", "IntegrationTestKey")


def site(snapshot, name, port):
    matches = [s for s in snapshot["apache"]["sites"] if s["server_name"] == name and s["port"] == port]
    assert len(matches) == 1, f"{name}:{port} not found exactly once"
    return matches[0]


def main(path):
    raw = open(path).read()
    for secret in SECRETS:
        assert secret not in raw, f"secret {secret!r} leaked into the snapshot"
    snap = json.loads(raw)

    assert snap["schema_version"] == 1
    assert snap["errors"] == [], snap["errors"]
    assert snap["server"]["hostname"]
    assert snap["server"]["interfaces"], "no network interface"
    assert snap["apache"]["version"].startswith("Apache/2.4")

    redirect = site(snap, "shop.example.test", 80)
    assert redirect["https"] is False

    shop = site(snap, "shop.example.test", 443)
    assert shop["type"] == "php-fpm", shop
    assert (shop["system_user"], shop["php_version"]) == ("shop", "8.3"), shop
    assert shop["owner_user"] == "shop", shop
    assert shop["cert_expires_at"], shop
    assert shop["aliases"] == ["www.shop.example.test"], shop
    assert [(r["db_name"], r["db_user"], r["db_host"]) for r in shop["db_refs"]] == [
        ("shop_prod", "shop", "127.0.0.1")
    ], shop["db_refs"]

    api = site(snap, "api.example.test", 80)
    assert api["type"] == "node", api
    assert (api["system_user"], api["app_dir"]) == ("app-api", "/srv/api"), api
    assert [(r["db_name"], r["db_user"]) for r in api["db_refs"]] == [("api_prod", "api")], api["db_refs"]

    static = site(snap, "static.example.test", 80)
    assert static["type"] == "static", static

    names = {d["name"] for d in snap["mysql"]["databases"]}
    assert {"shop_prod", "api_prod", "orphan_db"} <= names, names
    grants = {(g["user"], g["database"]): g["privileges"] for g in snap["mysql"]["grants"]}
    assert set(grants[("shop", "shop_prod")]) == {"SELECT", "INSERT", "UPDATE", "DELETE"}, grants
    print("integration OK:", len(snap["apache"]["sites"]), "sites,", len(names), "databases")


if __name__ == "__main__":
    main(sys.argv[1])
