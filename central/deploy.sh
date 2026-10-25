#!/bin/sh
# Met à jour le central : git pull, dépendances si composer.lock a changé, migrations, caches.
# À lancer en tant que propriétaire des fichiers, jamais en root (des caches appartenant à root
# donneraient une erreur 500) : sudo -u <propriétaire> /chemin/vers/central/deploy.sh
# POSIX sh : fonctionne aussi lancé par « sh deploy.sh ».
set -eu

main() {
    if [ "$(id -u)" -eq 0 ]; then
        echo "Ne lancez pas ce script en root : sudo -u <propriétaire du dépôt> $0" >&2
        exit 1
    fi
    cd "$(dirname "$(readlink -f "$0")")"

    local before
    before=$(git rev-parse HEAD)
    git pull --ff-only

    if [ ! -f vendor/autoload.php ] || ! git diff --quiet "$before" HEAD -- composer.lock; then
        composer install --no-dev --optimize-autoloader --no-interaction
    else
        echo "composer.lock inchangé : dépendances à jour."
    fi

    php artisan migrate --force
    php artisan optimize   # inclut filament:optimize
}

# Sur une seule ligne : git pull peut remplacer ce fichier pendant qu'il s'exécute.
main "$@"; exit
