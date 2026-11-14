# Mises à jour APT en attente : agent, central, API, Ansible

Date : 2026-11-14
Statut : validé par l'utilisateur, implémenté.

## Intention

L'utilisateur administre ses serveurs de façon centralisée. Il doit voir quels serveurs ont des mises à jour `apt` en attente, lesquelles, et lesquelles sont des correctifs de sécurité, puis les appliquer avec Ansible depuis Semaphore. StackCard est l'endroit choisi pour ce suivi. Il **observe**, il n'applique jamais rien : l'agent reste push-only et ne reçoit aucun ordre du central.

Consommateurs :
1. **L'utilisateur**, dans l'admin : vue par serveur (maintenance complète d'un serveur) et vue par paquet (campagne ciblée, typiquement en cas de faille).
2. **l'outil de suivi des CVE**, par l'API : « qui a ce paquet, en quelle version, et une version plus récente est-elle proposée ? ». La disponibilité d'un correctif pour une CVE, l'outil de suivi des CVE la vérifie lui-même auprès d'Ubuntu (USN, suivi des CVE) ; StackCard lui fournit les versions installées et les mises à jour en attente.
3. **Ansible**, par l'inventaire dynamique : groupes automatiques pour viser les serveurs à mettre à jour.

## Objectifs

- L'agent envoie, pour chaque serveur, les mises à jour `apt` en attente (paquet, version installée, version disponible, dépôt d'origine, sécurité oui/non), les paquets bloqués, le redémarrage requis et l'âge du cache APT.
- L'agent rafraîchit lui-même le cache APT selon une règle locale fixe, réglable par Ansible.
- L'admin montre les mises à jour par serveur et par paquet.
- L'API les expose pour l'outil de suivi des CVE.
- L'inventaire Ansible gagne des groupes automatiques.

## Non-objectifs

- Images Docker, dépendances Composer/npm, WordPress : plus tard.
- Appliquer une mise à jour, depuis StackCard ou l'agent : jamais.
- Interroger les avis de sécurité d'Ubuntu (USN, CVE) dans StackCard : c'est le rôle de l'outil de suivi des CVE.
- Configurer `unattended-upgrades`.

## Anti-objectifs

- L'agent qui installe, met à jour ou supprime quoi que ce soit, ou lance une autre commande APT que le rafraîchissement de la liste et une simulation.
- L'agent qui bloque une mise à jour lancée par Ansible ou `unattended-upgrades` (verrou APT tenu ou attendu).
- Une information présentée comme à jour alors que le cache APT est ancien : l'âge du cache est toujours visible.

## Contraintes

- Règles du `CLAUDE.md` : agent en bibliothèque standard Python (≥ 3.10, Ubuntu 22.04, 24.04 et 26.04), tout appel système par `util.run`/`util.run_full` avec des collecteurs à `runner` injectable et des tests sur des faux, chemins absolus des binaires système, environnement propre et locale C.
- Contrat de snapshot : section supplémentaire dans `sections` (schema_version 1 inchangé), enveloppe identique aux autres sections, règle de fidélité (`[]` = lu et rien trouvé, `null` = non lu avec `source_errors`), listes triées.
- Central : migration + `_release/`, `docs/api/` (OpenAPI + Postman) à jour, textes en français, tests Pest, champs de l'API listés un par un.
- Dépôt public : aucun nom de serveur, de client, IP réelle ni secret dans le code, les tests, la doc ou les messages de commit.
- Nouvelle version de l'agent (0.6.0) et son tag `agent-v0.6.0`. Déployer le central avant l'agent, comme pour 0.5.

## Décisions

### 1. Agent : section `apt_updates`

Nouvelle section horaire (pas hebdomadaire) dans `agent/discover_agent/sections/`, enregistrée dans `sections/__init__.py`.

**Rafraîchissement du cache** (décision de l'utilisateur : l'agent fait l'`apt update` lui-même) :
- Seulement si le cache a plus de `apt_refresh_hours` heures. Âge du cache = date du dernier rafraîchissement réussi (le builder choisit la source fiable sur 22.04, 24.04 et 26.04 : `/var/lib/apt/periodic/update-success-stamp`, mtime le plus récent des listes de `/var/lib/apt/lists/`, ou équivalent).
- Commande : `/usr/bin/apt-get update -q`, locale C, environnement propre, **sans attendre un verrou** : si APT est occupé (Ansible, `unattended-upgrades`, `apt-daily`), l'agent n'attend pas et passe son tour ; le cache est lu tel quel.
- Durée bornée (120 s proposé ; le builder ajuste le budget de la section en conséquence, sur le modèle des sections hebdomadaires à 300 s).
- Échec, verrou ou dépassement : `source_errors.apt_update` (`locked`, `timeout` ou le message), la section continue avec le cache existant. Un rafraîchissement réussi ou sauté n'apparaît **pas** dans `data` (valeur qui changerait à chaque passage).
- Au plus un rafraîchissement par passage.

**Réglage local** : `apt_refresh_hours` dans la section `[collect]` de `/etc/discover-agent/agent.conf`, comme `weekly_interval_days`. Défaut 6. Valeur absente, non entière ou < 1 → 6. Lu à chaque passage. Ce n'est pas un ordre du central : l'utilisateur le règle avec Ansible (ex. 1 pendant une crise, puis 6).

**Lecture** (après l'éventuel rafraîchissement) :
- Mises à jour en attente : simulation `apt-get -s` (le builder choisit `upgrade` ou `dist-upgrade` ; `dist-upgrade` recommandé pour voir aussi les nouveaux noyaux et dépendances), locale C, lecture des lignes `Inst` : paquet, architecture, version installée, version candidate, dépôts d'origine. Les paquets retenus par la mise en phase d'Ubuntu (phased updates) suivent ce que ferait `apt` : s'il ne les installerait pas, ils ne sont pas listés.
- `security` : vrai si l'un des dépôts d'origine de la version candidate est un dépôt `-security` (ou l'équivalent Ubuntu Pro ESM sécurité, que le builder identifie).
- Paquets bloqués : `apt-mark showhold`.
- Redémarrage requis : **déjà remonté** par la section `kernel` (`reboot_required`, `reboot_required_pkgs`, lus dans `/var/run/reboot-required*`). La section `apt_updates` ne le duplique pas ; le central le lit dans la dernière section `kernel`.

**Données** (`data`), valeurs d'exemple :
```json
{
  "cache_updated_at": "2026-11-14T06:12:00Z",
  "upgrades": [
    {"package": "openssl", "arch": "amd64", "installed": "3.0.13-0ubuntu3.4",
     "candidate": "3.0.13-0ubuntu3.5", "origins": ["noble-security", "noble-updates"], "security": true}
  ],
  "held": ["linux-image-generic"]
}
```
Listes triées. `cache_updated_at` ne change qu'au rafraîchissement (≤ 1 fois par `apt_refresh_hours`) : accepté, au prix d'une ligne d'historique de section par rafraîchissement. *(choix de Claude)* Une sous-source non lue vaut `null` avec son `source_errors`.

**Invariants (testés)** : l'agent ne lance que `apt-get update` (si dû) et des simulations `apt-get -s` ; jamais `install`, `upgrade` réel, `remove`, `dpkg --configure`. Il n'attend jamais un verrou APT. Chemins absolus, locale C, environnement propre.

### 2. Central : stockage

- `ServerSection::NAMES` gagne `apt_updates` ; l'historique des sections fonctionne comme pour les autres.
- Pour l'affichage, le filtrage et l'API, des données dérivées remplacées à chaque inventaire qui contient la section (comme `site_domains`). Le builder choisit la forme ; piste : une table `server_updates` (serveur, paquet, arch, installé, candidat, sécurité, origines) et, sur `servers`, des colonnes `updates_count`, `security_updates_count`, `reboot_required`, `apt_cache_updated_at`. Migration + `_release/`.
- **État de fraîcheur** `updates_state` par serveur, utilisé partout (admin, API, inventaire) *(règles : choix de Claude)* :
  - `unknown` : aucune section `apt_updates` valide reçue (agent < 0.6, ou seulement des erreurs). Aucun chiffre affiché.
  - `stale` : la dernière section est en erreur, ou `upgrades` vaut `null`, ou le cache APT a plus de 24 h. On **garde** les dernières données valides (lignes et compteurs), affichées avec la date de ces données et l'erreur s'il y en a une.
  - `ok` : section valide et cache de moins de 24 h.
  - **« à jour » (vert) n'est affiché que pour `ok` avec zéro mise à jour.** Un `stale` à zéro est gris (« à jour au … »).
  - Une section en erreur ne remplace pas les lignes dérivées ; une section valide les remplace en entier.
- Les groupes Ansible (§5) utilisent les dernières données connues, quel que soit l'état : mieux vaut viser un serveur de trop qu'en oublier un.
- Redémarrage requis : depuis la dernière section `kernel`.
- Version d'Ubuntu : la colonne `servers.os`.
- Versions installées pour l'API `/packages` : la version `installed` de `apt_updates` quand le paquet y figure (même instant que la version candidate), sinon la dernière section `packages` (également horaire). Le builder choisit entre lecture du JSON et table dérivée.

### 3. Admin

- **Liste des serveurs** : colonne « Mises à jour » : badge « N dont M sécu » (rouge si M > 0), « à jour » (vert), « inconnu » (gris, pas de données). Icône « redémarrage requis ». Au survol : âge du cache APT. Filtres : « avec mises à jour de sécurité », « redémarrage requis ». Un cache APT de plus de 24 h est signalé (couleur ou mention). *(seuil : choix de Claude)*
- **Fiche serveur** : onglet « Mises à jour » : une ligne par paquet (nom, installé, disponible, sécurité, origine), filtre sécurité ; en tête : âge du cache, redémarrage requis et paquets concernés, paquets bloqués.
- **Page flotte « Mises à jour »** (lecture seule, tous les rôles, comme « Domaines ») : une ligne par couple (paquet, version disponible) — deux versions candidates différentes (par ex. 22.04 et 24.04) font deux lignes *(choix de Claude)* : paquet, version(s) installée(s) → version disponible, sécurité, nombre et liste des serveurs concernés (liens vers leurs fiches). Filtre sécurité, recherche par paquet. Serveurs révoqués exclus.
- Pas de bouton d'action : StackCard n'applique rien.

### 4. API de lecture

Même protection que le reste (jeton `inventory:read`, `allowed_ips`), champs listés un par un.
- `GET /api/v1/servers` et `/servers/{server}` : `updates_state` (`ok` / `stale` / `unknown`), `updates_pending`, `security_updates_pending`, `reboot_required`, `apt_cache_updated_at` (compteurs `null` si `unknown`).
- `GET /api/v1/servers/{server}/updates` : le détail (liste des mises à jour, paquets bloqués, redémarrage, âge du cache, `collected_at` et erreurs de la section).
- `GET /api/v1/updates[?security=1][&package=<nom>]` : vue flotte par (paquet, version disponible), comme la page ; `package` en correspondance exacte.
- `GET /api/v1/packages?name=<nom>` (nom exact, obligatoire) : pour chaque serveur non révoqué qui a ce paquet : slug, version d'Ubuntu, version installée, version disponible (`null` si aucune mise à jour connue), sécurité, `updates_state` du serveur (pour distinguer « à jour » de « inconnu » ou « périmé »), `apt_cache_updated_at`, date de la section `packages`. Nom absent ou invalide → 422 JSON. C'est la requête de l'outil de suivi des CVE.
- Serveurs révoqués exclus des vues flotte.

### 5. Inventaire Ansible

Groupes automatiques supplémentaires, émis seulement s'ils ne sont pas vides : `updates_pending`, `security_updates`, `reboot_required`. Hostvars : `stackcard_updates_pending`, `stackcard_security_updates_pending`, `stackcard_reboot_required`.

### 6. Documentation

- `docs/ansible.md` : réglage `apt_refresh_hours` et playbook d'exemple (`community.general.ini_file` sur `/etc/discover-agent/agent.conf`, section `collect`) ; groupes `updates_pending`, `security_updates`, `reboot_required`.
- Spec agent 0.5 §4.7 (ou nouveau paragraphe) : schéma de la section `apt_updates` ; fixture `central/tests/Fixtures/snapshot.json`.
- `docs/install-agent.md`, `CLAUDE.md` (section, réglage, invariants), OpenAPI + Postman.

## Laissé au builder

- La source exacte de l'âge du cache, la commande de simulation (`upgrade` / `dist-upgrade`), la reconnaissance des dépôts de sécurité (dont ESM).
- La forme du stockage dérivé au central.
- La présentation précise des pages.
- Le découpage en commits.
