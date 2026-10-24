"""discover-agent command line: enroll, collect, run, status."""

import argparse
import json
import socket
import sys

from discover_agent import __version__, config, snapshot, transport, util

ENROLL_PATH = "/api/v1/enroll"
INVENTORY_PATH = "/api/v1/inventory"
TIMER = "discover-agent.timer"
# Enrolled (the token is used up) but the first inventory was not delivered: the timer retries it.
EXIT_ENROLLED_SEND_FAILED = 3


def identity():
    return {
        "machine_id": (util.read_text("/etc/machine-id") or "").strip() or None,
        "hostname": socket.gethostname(),
    }


def enable_timer():
    try:
        util.run(["systemctl", "enable", "--now", TIMER])
    except util.CommandError as exc:
        print(f"Attention : impossible d'activer {TIMER} ({exc})", file=sys.stderr)


def _error(message):
    print(message, file=sys.stderr)


def _describe(exc):
    return f"{type(exc).__name__}: {exc}"


def cmd_enroll(args, paths):
    url = args.url.rstrip("/")
    if not url.startswith("https://"):
        _error("L'URL du central doit commencer par https://")
        return 1
    # "-" keeps the token out of the process list and the shell history (install.sh).
    token = sys.stdin.readline().strip() if args.token == "-" else args.token
    if not token:
        _error("Aucun jeton d'enrôlement fourni.")
        return 1
    try:
        status, body = transport.post_json(
            url + ENROLL_PATH,
            {"enrollment_token": token, **identity()},
            ca_file=args.ca_file,
            pin_sha256=args.pin_sha256,
        )
    except Exception as exc:
        _error(f"Enrôlement impossible : {_describe(exc)}")
        return 1
    if status != 201 or not body.get("token"):
        _error(f"Enrôlement refusé (HTTP {status}) : {body.get('message', '')}")
        return 1
    cfg = config.Config(url=url, token=body["token"], ca_file=args.ca_file, pin_sha256=args.pin_sha256)
    config.save(paths, cfg)
    print(f"Serveur enrôlé (id {body.get('server_id')}).")
    enable_timer()
    return EXIT_ENROLLED_SEND_FAILED if send_inventory(cfg, paths) else 0


def send_inventory(cfg, paths):
    snap = snapshot.build_snapshot()
    state = config.load_state(paths)
    state["last_attempt_at"] = util.now_utc()
    state["collector_errors"] = snap.get("errors", [])
    try:
        status, body = transport.post_json(
            cfg.url + INVENTORY_PATH, snap,
            token=cfg.token, ca_file=cfg.ca_file, pin_sha256=cfg.pin_sha256,
        )
    except Exception as exc:
        status, error = None, _describe(exc)
    else:
        error = None if status == 200 else f"HTTP {status}: {body.get('message', '')}".strip()
    state["last_status"] = status
    state["last_error"] = error
    if error is None:
        state["last_success_at"] = state["last_attempt_at"]
    else:
        state.setdefault("last_success_at", None)
    config.save_state(paths, state)
    if error:
        _error(f"Envoi de l'inventaire échoué : {error}")
        return 1
    print(f"Inventaire envoyé ({len(state['collector_errors'])} erreur(s) de collecte).")
    return 0


def cmd_run(args, paths):
    cfg = config.load(paths)
    if cfg is None:
        _error("Agent non enrôlé : lancez d'abord `discover-agent enroll --url ... --token ...`.")
        return 2
    return send_inventory(cfg, paths)


def cmd_collect(args, paths):
    print(json.dumps(snapshot.build_snapshot(), indent=2, ensure_ascii=False))
    return 0


def cmd_status(args, paths):
    cfg = config.load(paths)
    state = config.load_state(paths)
    print(f"discover-agent {__version__}")
    if cfg is None:
        print("Enrôlé : non")
    else:
        print("Enrôlé : oui")
        print(f"Central : {cfg.url}")
        print(f"CA personnalisée : {cfg.ca_file or 'non'}")
        print(f"Clé épinglée : {'oui' if cfg.pin_sha256 else 'non'}")
    print(f"Dernière tentative : {state.get('last_attempt_at') or '-'}")
    print(f"Dernier succès : {state.get('last_success_at') or '-'}")
    print(f"Dernier code HTTP : {state.get('last_status') or '-'}")
    if state.get("last_error"):
        print(f"Dernière erreur : {state['last_error']}")
    for item in state.get("collector_errors", []):
        print(f"Erreur de collecte [{item['collector']}] : {item['message']}")
    return 0


def build_parser():
    parser = argparse.ArgumentParser(prog="discover-agent", description="Inventaire serveur Discover")
    parser.add_argument("--version", action="version", version=__version__)
    commands = parser.add_subparsers(dest="command", required=True)

    enroll = commands.add_parser("enroll", help="Enrôler ce serveur auprès du central")
    enroll.add_argument("--url", required=True)
    enroll.add_argument("--token", required=True, help="jeton d'enrôlement, ou - pour le lire sur l'entrée standard")
    enroll.add_argument("--ca-file")
    enroll.add_argument("--pin-sha256")
    enroll.set_defaults(handler=cmd_enroll)

    for name, handler, text in (
        ("collect", cmd_collect, "Afficher l'inventaire sans l'envoyer"),
        ("run", cmd_run, "Collecter et envoyer l'inventaire"),
        ("status", cmd_status, "Afficher l'état de l'agent"),
    ):
        commands.add_parser(name, help=text).set_defaults(handler=handler)
    return parser


def main(argv=None):
    args = build_parser().parse_args(argv)
    return args.handler(args, config.default_paths())
