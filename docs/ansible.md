# Inventaire Ansible (Semaphore UI)

StackCard est la **source de vérité** des serveurs : dans Ansible, le nom d'un hôte est le **slug StackCard**
du serveur. Le slug est figé au premier inventaire reçu et ne change plus ensuite : renommer le serveur
(son `name`) ne touche jamais le nom d'hôte Ansible. Avant de brancher Semaphore sur l'inventaire, cliquez sur
« Figer les slugs » (liste des serveurs, réservé aux administrateurs) pour verrouiller ceux qui ne le sont pas encore.

Le central expose un inventaire dynamique, `GET /api/v1/inventory/ansible`, et le script
`docs/ansible/stackcard_inventory.py` (bibliothèque standard Python ≥ 3.10, sans pip) le transmet à Ansible.

## Ce que contient l'inventaire

- Les serveurs **actifs** qui ont une adresse : celle saisie à la main (« adresse de connexion »), sinon l'IP
  principale remontée par l'agent. Les serveurs en attente, révoqués ou sans adresse sont absents.
- Variables d'hôte : `ansible_host`, `ansible_port` (seulement si un port est défini), `stackcard_id`,
  `stackcard_name`, `stackcard_hostname`, `stackcard_fqdn`, `stackcard_os`, `stackcard_stale`,
  `stackcard_last_seen_at` (ISO 8601, UTC).
- Groupes, émis seulement s'ils ne sont pas vides : `ubuntu_22_04`, `ubuntu_24_04`, `ubuntu_26_04` (selon l'OS),
  `apache`, `mysql`, `docker` (service détecté) et `stale` (serveur muet depuis plus de 3 h : ses données et son
  adresse peuvent être périmées). Le groupe `all` liste tous les hôtes et ces groupes.

## 1. Créer le client API

Dans l'administration (réservée aux admins) : **Réglages > Clients API**, créer un client avec l'adresse IP
**de Semaphore** dans la liste des adresses autorisées (une liste vide refuse tout le monde), puis copier le
jeton affiché une seule fois. Il porte la capacité `inventory:read`.

Si Semaphore passe par un reverse proxy, celui-ci doit figurer dans `DISCOVER_TRUSTED_PROXIES` du central,
sinon l'adresse vue est celle du proxy.

## 2. Déclarer l'environnement dans Semaphore

Dans **Environment**, section *Environment variables* :

```json
{
  "STACKCARD_URL": "https://stackcard.example.com",
  "STACKCARD_TOKEN": "<jeton>"
}
```

Mettre `STACKCARD_TOKEN` dans les *secrets* de l'environnement. `STACKCARD_URL` est la base du central et doit
être en **HTTPS** (le script refuse le reste ; le certificat est toujours vérifié). Pour une autorité de
certification interne, ajouter `STACKCARD_CA_FILE` (chemin d'un fichier PEM lisible par Semaphore).

## 3. Déposer le script et choisir l'inventaire

1. Copier `stackcard_inventory.py` à la racine du dépôt de playbooks, exécutable (`chmod +x`).
2. Dans Semaphore, **Inventory** : type **File**, chemin `stackcard_inventory.py`, dépôt de playbooks.
3. Associer cet inventaire et l'environnement du point 2 aux modèles de tâches.

## Essai en ligne de commande

```bash
export STACKCARD_URL=https://stackcard.example.com
export STACKCARD_TOKEN=<jeton>

./stackcard_inventory.py --list                       # le JSON de l'API
ansible -i stackcard_inventory.py ubuntu_22_04 -m ping
ansible -i stackcard_inventory.py all --limit web01 -m setup
```

`--host <slug>` affiche `{}` : les variables de tous les hôtes sont déjà dans `_meta` de `--list`.
En cas de variable manquante, d'URL non HTTPS ou de réponse autre que 200, le script écrit un message sur
stderr et sort avec le code 1.

## Tests du script

```bash
cd docs/ansible && python3 -m unittest
```
