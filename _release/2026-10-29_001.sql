-- Discover central : pare-feu UFW (migration 2026_10_29_000000).
-- À appliquer après 2026-10-25_002.sql : mysql -u scanner -p scanner < 2026-10-29_001.sql
-- Généré le 2026-10-29 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).

SET NAMES utf8mb4;

CREATE TABLE `firewall_rules` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `server_id` bigint unsigned NOT NULL,
  `position` int unsigned NOT NULL,
  `destination` varchar(255) NOT NULL,
  `action` varchar(16) NOT NULL,
  `source` varchar(255) NOT NULL,
  `ipv6` tinyint(1) NOT NULL,
  `comment` varchar(255) NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `firewall_rules` ADD CONSTRAINT `firewall_rules_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE;

ALTER TABLE `servers` ADD `ufw_status` varchar(16) NULL;
ALTER TABLE `servers` ADD `ufw_logging` varchar(32) NULL;
ALTER TABLE `servers` ADD `ufw_default_incoming` varchar(16) NULL;
ALTER TABLE `servers` ADD `ufw_default_outgoing` varchar(16) NULL;
ALTER TABLE `servers` ADD `ufw_default_routed` varchar(16) NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_29_000000_add_firewall', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
