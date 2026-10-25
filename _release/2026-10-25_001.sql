-- Discover central : technos des sites et applications Node (migration 2026_10_25_000000).
-- À appliquer après 2026-10-24_001.sql : mysql -u scanner -p scanner < 2026-10-25_001.sql
-- Généré le 2026-10-25 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).

SET NAMES utf8mb4;

CREATE TABLE `node_apps` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `server_id` bigint unsigned NOT NULL,
  `name` varchar(255) NULL,
  `version` varchar(32) NULL,
  `node_version` varchar(32) NULL,
  `listen` json NOT NULL,
  `user` varchar(255) NULL,
  `group` varchar(255) NULL,
  `app_dir` varchar(1024) NULL,
  `process_manager` varchar(255) NULL,
  `technologies` json NOT NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `node_apps` ADD CONSTRAINT `node_apps_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE;

ALTER TABLE `sites` ADD `technologies` json NULL;
ALTER TABLE `sites` ADD `node_app_id` bigint unsigned NULL;
ALTER TABLE `sites` ADD CONSTRAINT `sites_node_app_id_foreign` FOREIGN KEY (`node_app_id`) REFERENCES `node_apps` (`id`) ON DELETE SET NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_25_000000_add_technologies_and_node_apps', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
