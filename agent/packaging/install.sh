#!/bin/sh
# Installe ou met à jour l'agent Discover, puis enrôle ce serveur auprès du central.
#
#   curl -fsSL https://github.com/LarssenConsulting/StackCard/releases/latest/download/install.sh | sudo sh
#
# Le script demande l'adresse (FQDN) du central puis le jeton d'enrôlement, créé dans le
# central sur la fiche du serveur. Le central doit répondre en HTTPS avec un certificat valide.
# Relancé sur un serveur déjà installé, il met l'agent à jour si une version plus récente est
# publiée et propose de remplacer le jeton.
#
# Sans questions (Ansible, plusieurs serveurs) :
#   curl -fsSL .../install.sh | sudo DISCOVER_URL=<fqdn> DISCOVER_TOKEN=<jeton> sh
set -eu

BASE=${DISCOVER_DOWNLOAD_BASE:-https://github.com/LarssenConsulting/StackCard/releases/latest/download}
TTY=${DISCOVER_TTY:-/dev/tty}
DEB=discover-agent_latest_all.deb
TOKEN_ATTEMPTS=3
EXIT_ENROLLED_SEND_FAILED=3   # discover-agent enroll: enrôlé, premier inventaire non envoyé

tty_open=0
tmp=""

say() { printf '%s\n' "$*" >&2; }
die() { say "Erreur : $*"; exit 1; }

cleanup() {
    if [ "$tty_open" = 1 ]; then stty echo <&3 2>/dev/null || true; fi
    if [ -n "$tmp" ]; then rm -rf "$tmp"; fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# Questions are read from the terminal, not stdin: stdin is the script itself under `curl | sh`.
open_tty() {
    [ "$tty_open" = 1 ] && return 0
    # A failed `exec` redirection would end the shell: try it in a subshell first.
    ( exec 3<"$TTY" ) 2>/dev/null || return 1
    exec 3<"$TTY"
    tty_open=1
}

ask() {
    open_tty || die "aucun terminal pour répondre aux questions. Relancez le script dans un terminal, ou définissez DISCOVER_URL et DISCOVER_TOKEN."
    printf '%s' "$1" >&2
    REPLY=""
    IFS= read -r REPLY <&3 || [ -n "$REPLY" ] || die "saisie interrompue."
}

ask_secret() {
    if open_tty; then stty -echo <&3 2>/dev/null || true; fi
    ask "$1"
    stty echo <&3 2>/dev/null || true
    printf '\n' >&2
}

# "https://sentinel.example.fr/" -> "sentinel.example.fr"; empty when it is not a host[:port].
to_host() {
    host=${1#https://}
    host=${host#http://}
    host=${host%%/*}
    case "$host" in
        "" | *[!A-Za-z0-9.:-]*) echo "" ;;
        *) echo "$host" ;;
    esac
}

# Laravel's health route: answers 200 once TLS is valid and the application is up.
reachable() {
    curl -fsS --proto =https --max-time 15 -o /dev/null "https://$1/up"
}

install_package() {
    say "Téléchargement de l'agent depuis $BASE…"
    tmp=$(mktemp -d)
    chmod 0755 "$tmp"
    curl -fsSL --proto =https -o "$tmp/$DEB" "$BASE/$DEB" || die "téléchargement de $BASE/$DEB impossible."
    curl -fsSL --proto =https -o "$tmp/$DEB.sha256" "$BASE/$DEB.sha256" \
        || die "téléchargement de $BASE/$DEB.sha256 impossible."
    (cd "$tmp" && sha256sum -c --status "$DEB.sha256") \
        || die "empreinte SHA-256 du paquet invalide : installation annulée."
    chmod 0644 "$tmp/$DEB"

    available=$(dpkg-deb -f "$tmp/$DEB" Version)
    installed=$(dpkg-query -W -f='${db:Status-Status}:${Version}' discover-agent 2>/dev/null || true)
    case "$installed" in
        installed:*) installed=${installed#installed:} ;;
        *) installed="" ;;
    esac

    if [ -n "$installed" ] && ! dpkg --compare-versions "$available" gt "$installed"; then
        say "Agent déjà à jour (version $installed)."
        return 0
    fi
    say "Installation de discover-agent $available…"
    if ! DEBIAN_FRONTEND=noninteractive apt-get install -y "$tmp/$DEB"; then
        DEBIAN_FRONTEND=noninteractive apt-get update
        DEBIAN_FRONTEND=noninteractive apt-get install -y "$tmp/$DEB" || die "installation du paquet échouée."
    fi
}

# $1: host proposed by default (current central), may be empty.
choose_host() {
    if [ -n "${DISCOVER_URL:-}" ]; then
        host=$(to_host "$DISCOVER_URL")
        [ -n "$host" ] || die "DISCOVER_URL invalide : $DISCOVER_URL"
        reachable "$host" || die "le central https://$host ne répond pas avec un certificat valide."
        return 0
    fi
    while :; do
        ask "Adresse (FQDN) du central Discover${1:+ [$1]} : "
        host=$(to_host "${REPLY:-$1}")
        if [ -z "$host" ]; then
            say "Adresse invalide : indiquez par exemple sentinel.example.fr"
        elif reachable "$host"; then
            return 0
        else
            say "https://$host ne répond pas avec un certificat valide (DNS, pare-feu ou certificat ?)."
        fi
    done
}

# $1: host proposed by default.
enroll() {
    choose_host "$1"
    attempt=0
    while :; do
        if [ -n "${DISCOVER_TOKEN:-}" ]; then
            token=$DISCOVER_TOKEN
        else
            ask_secret "Jeton d'enrôlement : "
            token=$REPLY
            [ -n "$token" ] || continue
        fi
        status=0
        printf '%s\n' "$token" | discover-agent enroll --url "https://$host" --token - || status=$?
        case "$status" in
            0) say "Serveur enrôlé auprès de https://$host." ; return 0 ;;
            "$EXIT_ENROLLED_SEND_FAILED")
                say "Serveur enrôlé, mais le premier inventaire n'est pas parti : il sera renvoyé dans l'heure."
                say "Détails : sudo discover-agent status"
                return 0 ;;
        esac
        attempt=$((attempt + 1))
        if [ -n "${DISCOVER_TOKEN:-}" ] || [ "$attempt" -ge "$TOKEN_ATTEMPTS" ]; then
            die "enrôlement refusé (voir le message ci-dessus)."
        fi
        say "Enrôlement refusé : vérifiez le jeton (valable 24 h, à usage unique) et réessayez."
    done
}

case "$BASE" in
    https://*) ;;
    *) die "DISCOVER_DOWNLOAD_BASE doit commencer par https:// ($BASE)." ;;
esac
[ "$(id -u)" = 0 ] || die "lancez le script en root (… | sudo sh)."
for cmd in curl sha256sum dpkg dpkg-deb dpkg-query apt-get; do
    command -v "$cmd" >/dev/null 2>&1 || die "commande $cmd introuvable (Ubuntu 22.04 ou 24.04 attendu)."
done

install_package

# `discover-agent status` prints "Central : <url>" once the server is enrolled.
current=$(discover-agent status 2>/dev/null | sed -n 's/^Central : //p')
if [ -z "$current" ]; then
    enroll ""
elif [ -n "${DISCOVER_TOKEN:-}" ]; then
    enroll "$(to_host "$current")"
elif open_tty; then
    ask "Ce serveur est déjà enrôlé auprès de $current. Mettre à jour le jeton ? [o/N] "
    case "$REPLY" in
        o | O | oui | Oui | OUI | y | Y | yes) enroll "$(to_host "$current")" ;;
        *) say "Jeton inchangé." ;;
    esac
else
    say "Déjà enrôlé auprès de $current : jeton inchangé."
fi
