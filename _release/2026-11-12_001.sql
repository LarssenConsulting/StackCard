-- Discover central : slug permanent et adresse de connexion manuelle des serveurs (migration 2026_11_12_000000).
-- À appliquer après 2026-11-01_003.sql : mysql -u scanner -p scanner < 2026-11-12_001.sql
-- Généré le 2026-11-12 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).
-- Slugs provisoires (srv-<id>) : à relire dans l'admin puis figer.

SET NAMES utf8mb4;

ALTER TABLE `servers` ADD `slug` varchar(64) NULL AFTER `name`;
ALTER TABLE `servers` ADD `slug_locked_at` datetime NULL AFTER `slug`;
ALTER TABLE `servers` ADD `connect_address` varchar(255) NULL AFTER `slug_locked_at`;
ALTER TABLE `servers` ADD `connect_port` smallint unsigned NULL AFTER `connect_address`;

UPDATE `servers` SET `slug` = CONCAT('srv-', `id`) WHERE `slug` IS NULL;

ALTER TABLE `servers` ADD UNIQUE `servers_slug_unique`(`slug`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_12_000000_add_slug_and_connection_to_servers', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
