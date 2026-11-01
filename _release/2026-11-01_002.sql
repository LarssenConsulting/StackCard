-- Discover central : clients de l'API de lecture (migration 2026_11_01_100000).
-- À appliquer après 2026-11-01_001.sql : mysql -u scanner -p scanner < 2026-11-01_002.sql
-- Généré le 2026-11-01 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).

SET NAMES utf8mb4;

CREATE TABLE `api_clients` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `allowed_ips` json NOT NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `api_clients` ADD UNIQUE `api_clients_name_unique`(`name`);

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_01_100000_create_api_clients_table', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
