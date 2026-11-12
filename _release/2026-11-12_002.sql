-- Discover central : table dérivée des noms de domaine des sites (migration 2026_11_12_100000).
-- À appliquer après 2026-11-12_001.sql : mysql -u scanner -p scanner < 2026-11-12_002.sql
-- Généré le 2026-11-12 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).
-- La table se remplit au prochain inventaire de chaque serveur (≤ 1 h) : le remplissage depuis le JSON
-- des alias (la migration Laravel le fait) n'est pas reproduit en SQL.

SET NAMES utf8mb4;

CREATE TABLE `site_domains` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_id` bigint unsigned NOT NULL,
  `server_id` bigint unsigned NOT NULL,
  `domain` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `role` varchar(16) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `site_domains_site_id_domain_unique` (`site_id`, `domain`),
  KEY `site_domains_server_id_foreign` (`server_id`),
  KEY `site_domains_domain_index` (`domain`),
  CONSTRAINT `site_domains_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE,
  CONSTRAINT `site_domains_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_12_100000_create_site_domains_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
