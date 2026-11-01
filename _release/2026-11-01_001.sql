-- Discover central : états détaillés des serveurs, avec historique (migration 2026_11_01_000000).
-- À appliquer après 2026-10-29_001.sql : mysql -u scanner -p scanner < 2026-11-01_001.sql
-- Généré le 2026-11-01 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).

SET NAMES utf8mb4;

CREATE TABLE `server_sections` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `server_id` bigint unsigned NOT NULL,
  `section` varchar(32) NOT NULL,
  `collected_at` timestamp NULL,
  `sha256` varchar(64) NULL,
  `error` text NULL,
  `source_errors` json NULL,
  `data` json NULL,
  `first_seen_at` timestamp NOT NULL,
  `last_seen_at` timestamp NOT NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `server_sections` ADD CONSTRAINT `server_sections_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE;
ALTER TABLE `server_sections` ADD INDEX `server_sections_server_id_section_id_index`(`server_id`, `section`, `id`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_000000_create_server_sections_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
