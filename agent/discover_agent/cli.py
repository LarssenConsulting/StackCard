"""discover-agent command line: enroll, collect, run, status."""

import argparse
import json
import socket
import sys

from discover_agent import __version__, config, sections, snapshot, transport, util

ENROLL_PATH = "/api/v1/enroll"
INVENTORY_PATH = "/api/v1/inventory"
TIMER = "discover-agent.timer"
# Enrolled (the token is used up) but the first inventory was not delivered: the timer retries it.
EXIT_ENROLLED_SEND_FAILED = 3
# The socket timeout also bounds writing the body, up to 20 MiB on a slow link.
INVENTORY_TIMEOUT = 300


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
    previous = config.load(paths)
    cfg = config.Config(
        url=url, token=body["token"], ca_file=args.ca_file, pin_sha256=args.pin_sha256,
        weekly_interval_days=previous.weekly_interval_days if previous else config.DEFAULT_WEEKLY_INTERVAL_DAYS,
    )
    config.save(paths, cfg)
    print(f"Serveur enrôlé (id {body.get('server_id')}).")
    enable_timer()
    # The weekly sections take minutes and belong in the sandbox: the timer runs them.
    return EXIT_ENROLLED_SEND_FAILED if send_inventory(cfg, paths, weekly=False) else 0


def send_inventory(cfg, paths, weekly=True):
    state = config.load_state(paths)
    now = util.now_utc()
    due = sections.due_weekly(state, cfg.weekly_interval_days, now) if weekly else set()
    snap = snapshot.build_snapshot(sections_due=due)
    # Saved below whatever the send does: a failing central must not rerun them every hour.
    sections.record_attempt(state, snap.get("sections", {}), now)
    state["last_attempt_at"] = now
    state["collector_errors"] = snap.get("errors", [])
    try:
        status, body = transport.post_json(
            cfg.url + INVENTORY_PATH, snap,
            token=cfg.token, ca_file=cfg.ca_file, pin_sha256=cfg.pin_sha256, timeout=INVENTORY_TIMEOUT,
        )
    except Exception as exc:
        status, error = None, _describe(exc)
    else:
        error = None if status == 200 else f"HTTP {status}: {body.get('message', '')}".strip()
    state["last_status"] = status
    state["last_error"] = error
    if error is None:
        state["last_success_at"] = state["last_attempt_at"]
        # Only a delivered result moves the weekly schedule; a lost one is retried 24 h after the attempt.
        sections.record_weekly(state, snap.get("sections", {}), cfg.weekly_interval_days, now)
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
    # Weekly (level 3) sections run only with --full; collect never touches state.json.
    snap = snapshot.build_snapshot(sections_due=set(sections.WEEKLY)) if args.full else snapshot.build_snapshot()
    print(json.dumps(snap, indent=2, ensure_ascii=False))
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
        collector, message = item["collector"], item["message"]
        if collector.startswith("sections."):
            print(f"Section en panne [{collector[len('sections.'):]}] : {message}")
        else:
            print(f"Erreur de collecte [{collector}] : {message}")
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

    collect = commands.add_parser("collect", help="Afficher l'inventaire sans l'envoyer")
    collect.add_argument("--full", action="store_true", help="exécuter aussi les sections hebdomadaires (lent)")
    collect.set_defaults(handler=cmd_collect)

    for name, handler, text in (
        ("run", cmd_run, "Collecter et envoyer l'inventaire"),
        ("status", cmd_status, "Afficher l'état de l'agent"),
    ):
        commands.add_parser(name, help=text).set_defaults(handler=handler)
    return parser


def main(argv=None):
    args = build_parser().parse_args(argv)
    return args.handler(args, config.default_paths())
