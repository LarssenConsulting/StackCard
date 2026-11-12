-- Discover central : IP principale validée des serveurs (migration 2026_11_12_200000).
-- À appliquer après 2026-11-12_002.sql : mysql -u scanner -p scanner < 2026-11-12_003.sql
-- L'IP connue aujourd'hui est validée d'office ; un changement ultérieur attend un admin.

SET NAMES utf8mb4;

ALTER TABLE `servers` ADD `approved_ip` varchar(45) NULL AFTER `primary_ip`;

UPDATE `servers` SET `approved_ip` = `primary_ip` WHERE `primary_ip` IS NOT NULL;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_12_200000_add_approved_ip_to_servers', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
