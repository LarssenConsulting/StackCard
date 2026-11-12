-- Discover central : exclusion d'un serveur de l'inventaire Ansible (migration 2026_11_12_300000).
-- À appliquer après 2026-11-12_003.sql : mysql -u scanner -p scanner < 2026-11-12_004.sql

SET NAMES utf8mb4;

ALTER TABLE `servers` ADD `ansible_excluded` tinyint(1) NOT NULL DEFAULT '0' AFTER `connect_port`;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_12_300000_add_ansible_excluded_to_servers', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
