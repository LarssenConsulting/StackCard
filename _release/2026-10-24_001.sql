-- Discover central : schéma initial (base vide, toutes migrations jusqu'à 2026_10_14_184308).
-- À appliquer sur la base vide du central (ex. scanner) : mysql -u scanner -p scanner < 2026-10-24_001.sql
-- Généré le 2026-10-24 depuis MySQL 8.0 (MAMP) et vérifié par rechargement sur ce même serveur (non testé sur MariaDB).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `db_databases` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `size_bytes` bigint unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `db_databases_server_id_name_index` (`server_id`,`name`),
  CONSTRAINT `db_databases_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `db_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `db_user_id` bigint unsigned NOT NULL,
  `database_id` bigint unsigned DEFAULT NULL,
  `privileges` json NOT NULL,
  `global` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `db_grants_db_user_id_foreign` (`db_user_id`),
  KEY `db_grants_database_id_foreign` (`database_id`),
  CONSTRAINT `db_grants_database_id_foreign` FOREIGN KEY (`database_id`) REFERENCES `db_databases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `db_grants_db_user_id_foreign` FOREIGN KEY (`db_user_id`) REFERENCES `db_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `db_users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `user` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `host` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `db_users_server_id_user_index` (`server_id`,`user`),
  CONSTRAINT `db_users_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `enrollment_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `enrollment_tokens_token_hash_unique` (`token_hash`),
  KEY `enrollment_tokens_server_id_foreign` (`server_id`),
  CONSTRAINT `enrollment_tokens_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `server_ips` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `interface` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `prefix` tinyint unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `server_ips_server_id_foreign` (`server_id`),
  KEY `server_ips_address_index` (`address`),
  CONSTRAINT `server_ips_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `servers` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `hostname` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fqdn` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `machine_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `primary_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `os` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kernel` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `virtualization` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `apache_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mysql_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `agent_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `last_seen_at` timestamp NULL DEFAULT NULL,
  `last_source_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `servers_machine_id_unique` (`machine_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `site_db_refs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `site_id` bigint unsigned NOT NULL,
  `db_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `db_user` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `db_host` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `database_id` bigint unsigned DEFAULT NULL,
  `db_user_id` bigint unsigned DEFAULT NULL,
  `resolution` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unresolved',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `site_db_refs_site_id_foreign` (`site_id`),
  KEY `site_db_refs_database_id_foreign` (`database_id`),
  KEY `site_db_refs_db_user_id_foreign` (`db_user_id`),
  CONSTRAINT `site_db_refs_database_id_foreign` FOREIGN KEY (`database_id`) REFERENCES `db_databases` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_db_refs_db_user_id_foreign` FOREIGN KEY (`db_user_id`) REFERENCES `db_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `site_db_refs_site_id_foreign` FOREIGN KEY (`site_id`) REFERENCES `sites` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sites` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `server_name` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `aliases` json NOT NULL,
  `port` int unsigned DEFAULT NULL,
  `https` tinyint(1) NOT NULL DEFAULT '0',
  `cert_expires_at` datetime DEFAULT NULL,
  `type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_root` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `app_dir` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `upstream` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `php_version` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_user` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `system_group` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_user` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_group` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `process_manager` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `config_file` varchar(1024) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `partial` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `sites_server_id_foreign` (`server_id`),
  KEY `sites_server_name_index` (`server_name`),
  CONSTRAINT `sites_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `snapshots` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `server_id` bigint unsigned NOT NULL,
  `received_at` timestamp NOT NULL,
  `source_ip` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `schema_version` smallint unsigned NOT NULL,
  `payload` json NOT NULL,
  `error_count` int unsigned NOT NULL DEFAULT '0',
  `warnings` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `snapshots_server_id_foreign` (`server_id`),
  KEY `snapshots_received_at_index` (`received_at`),
  CONSTRAINT `snapshots_server_id_foreign` FOREIGN KEY (`server_id`) REFERENCES `servers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `app_authentication_secret` text COLLATE utf8mb4_unicode_ci,
  `app_authentication_recovery_codes` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` VALUES (4,'2026_10_14_000000_create_inventory_tables',1);
INSERT INTO `migrations` VALUES (5,'2026_10_14_182331_create_personal_access_tokens_table',1);
INSERT INTO `migrations` VALUES (6,'2026_10_14_184308_add_two_factor_columns_to_users_table',1);

SET FOREIGN_KEY_CHECKS = 1;
