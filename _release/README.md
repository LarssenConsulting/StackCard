# Scripts SQL de mise à jour du central

Chaque fichier contient le SQL à appliquer sur la base MySQL/MariaDB du central lors d'une mise à
jour, pour les installations qui ne lancent pas `php artisan migrate`.

## Nommage

`YYYY-MM-DD_NNN.sql` : date de création, puis un numéro sur trois chiffres qui repart à `001`
chaque jour et s'incrémente pour une même date (`2026-10-24_001.sql`, `2026-10-24_002.sql`…).

## Règles

- Appliquer les fichiers **dans l'ordre des noms**, une seule fois chacun.
- `2026-10-24_001.sql` est le schéma initial complet : il ne s'applique que sur une base vide.
- Chaque nouvelle migration Laravel donne un nouveau fichier ici, qui contient aussi
  l'`INSERT INTO migrations` correspondant : `php artisan migrate` reste ainsi cohérent sur une base
  mise à jour à la main.
- Le SQL d'une migration s'obtient avec `php artisan migrate --pretend` sur une base MySQL à jour.

## Appliquer

```bash
mysql -u scanner -p scanner < _release/2026-10-24_001.sql
```
