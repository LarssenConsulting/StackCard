-- Discover central : rôles des comptes et réglages du site (migration 2026_11_01_200000).
-- À appliquer après 2026-11-01_002.sql : mysql -u scanner -p scanner < 2026-11-01_003.sql
-- Généré le 2026-11-01 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).
-- Tous les comptes existants deviennent administrateurs.

SET NAMES utf8mb4;

ALTER TABLE `users` ADD `role` varchar(255) NOT NULL DEFAULT 'user' AFTER `password`;
UPDATE `users` SET `role` = 'admin';

CREATE TABLE `settings` (
  `key` varchar(255) NOT NULL,
  `value` text NULL,
  PRIMARY KEY (`key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_200000_add_roles_and_settings', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
