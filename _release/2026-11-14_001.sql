-- Discover central : mises à jour APT en attente (migration 2026_11_14_000000).
-- À appliquer après 2026-11-12_004.sql : mysql -u scanner -p scanner < 2026-11-14_001.sql
-- Généré le 2026-11-14 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).
-- Aucun remplissage : la table et les colonnes se remplissent au prochain inventaire de chaque serveur
-- (≤ 1 h) équipé d'un agent qui envoie la section `apt_updates`.

SET NAMES utf8mb4;

CREATE TABLE `server_updates` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `package` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `arch` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `installed_version` varchar(255) NULL DEFAULT NULL,
  `candidate_version` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `security` tinyint(1) NOT NULL DEFAULT '0',
  `origins` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `server_updates_server_id_package_arch_unique` (`server_id`, `package`, `arch`),
  KEY `server_updates_package_index` (`package`),
  CONSTRAINT `server_updates_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `servers`
  ADD `updates_count` int unsigned NULL,
  ADD `security_updates_count` int unsigned NULL,
  ADD `apt_cache_updated_at` datetime NULL,
  ADD `updates_received_at` datetime NULL,
  ADD `updates_error` varchar(4000) NULL,
  ADD `reboot_required` tinyint(1) NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_14_000000_add_apt_updates', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
