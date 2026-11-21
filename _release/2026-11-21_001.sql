-- Pare-feu qui filtrent le trafic entrant (UFW, nftables, iptables, iptables-legacy, Fail2Ban),
-- calculés à chaque inventaire ; NULL = inconnu jusqu'au prochain inventaire.
ALTER TABLE `servers`
  ADD `firewall_engines` json NULL AFTER `ufw_default_routed`;

INSERT INTO `migrations` (`migration`, `batch`)
SELECT '2026_11_21_000000_add_firewall_engines_to_servers', COALESCE(MAX(`batch`), 0) + 1 FROM `migrations`;
