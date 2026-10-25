-- Discover central : conteneurs Docker (migration 2026_10_25_100000).
-- À appliquer après 2026-10-25_001.sql : mysql -u scanner -p scanner < 2026-10-25_002.sql
-- Généré le 2026-10-25 avec `php artisan migrate --pretend` sur MySQL 8.0 (MAMP).

SET NAMES utf8mb4;

CREATE TABLE `docker_containers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `server_id` bigint unsigned NOT NULL,
  `container_id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `image` varchar(255) NULL,
  `image_version` varchar(64) NULL,
  `state` varchar(32) NULL,
  `ports` json NOT NULL,
  `compose_project` varchar(255) NULL,
  `compose_service` varchar(255) NULL,
  `compose_dir` varchar(1024) NULL,
  `restart_policy` varchar(32) NULL,
  `started_at` timestamp NULL,
  `created_at` timestamp NULL,
  `updated_at` timestamp NULL
) DEFAULT CHARACTER SET utf8mb4 COLLATE 'utf8mb4_unicode_ci';
ALTER TABLE `docker_containers` ADD CONSTRAINT `docker_containers_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE;

ALTER TABLE `sites` ADD `docker_container_id` bigint unsigned NULL;
ALTER TABLE `sites` ADD CONSTRAINT `sites_docker_container_id_foreign` FOREIGN KEY (`docker_container_id`) REFERENCES `docker_containers` (`id`) ON DELETE SET NULL;

ALTER TABLE `servers` ADD `docker_version` varchar(255) NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_10_25_100000_add_docker_containers', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
