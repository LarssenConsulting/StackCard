<?php

namespace App\Support;

use App\Models\ServerSection;

/**
 * What the `network` section says about nftables, iptables and Fail2Ban, ready for a table.
 * `null` in the data means "not read", `[]` means "read, nothing there": they are told apart.
 */
class FirewallEngines
{
    private const TOO_OLD = 'Non remonté : il faut un agent 0.5.2 ou plus récent';

    /** @return array{status: string, rows: list<array<string, mixed>>} */
    public static function nftables(?ServerSection $network): array
    {
        if (($blocked = self::blocked($network)) !== null) {
            return ['status' => $blocked, 'rows' => []];
        }
        $ruleset = $network->data['nft'] ?? null;
        if (! is_array($ruleset)) {
            return ['status' => self::notRead($network->source_errors['nft'] ?? null), 'rows' => []];
        }

        return ['status' => self::ruleLabel(NftRuleset::ruleCount($ruleset)), 'rows' => NftRuleset::rows($ruleset)];
    }

    /** @return array{status: string, rows: list<array<string, mixed>>} */
    public static function iptables(?ServerSection $network, string $key): array
    {
        if (($blocked = self::blocked($network)) !== null) {
            return ['status' => $blocked, 'rows' => []];
        }
        if (! array_key_exists($key, $network->data)) {
            return ['status' => self::TOO_OLD, 'rows' => []];
        }

        $statuses = [];
        $rows = [];
        foreach (['v4' => 'IPv4', 'v6' => 'IPv6'] as $family => $label) {
            $lines = $network->data[$key][$family] ?? null;
            if (is_array($lines)) {
                $statuses[] = "{$label} : ".self::ruleLabel(IptablesRules::ruleCount($lines));
                array_push($rows, ...IptablesRules::rows($lines, $label));
            } else {
                $statuses[] = "{$label} : ".self::notRead($network->source_errors["{$key}.{$family}"] ?? null);
            }
        }

        return ['status' => implode(' · ', $statuses), 'rows' => $rows];
    }

    /** @return array{status: string, rows: list<array<string, mixed>>} */
    public static function fail2ban(?ServerSection $network): array
    {
        if (($blocked = self::blocked($network)) !== null) {
            return ['status' => $blocked, 'rows' => []];
        }
        if (! array_key_exists('fail2ban', $network->data)) {
            return ['status' => self::TOO_OLD, 'rows' => []];
        }
        $state = $network->data['fail2ban'];
        if (! is_array($state)) {
            return ['status' => self::notRead($network->source_errors['fail2ban'] ?? null), 'rows' => []];
        }

        $jails = array_values(array_filter((array) ($state['jails'] ?? []), 'is_array'));
        $status = [
            'Service '.match ($state['active'] ?? null) {
                'active' => 'actif',
                'inactive' => 'inactif',
                default => is_scalar($state['active'] ?? null) ? (string) $state['active'] : 'état inconnu',
            },
            match ($state['enabled'] ?? null) {
                'enabled' => 'lancé au démarrage',
                'disabled' => 'non lancé au démarrage',
                default => is_scalar($state['enabled'] ?? null) ? (string) $state['enabled'] : 'démarrage inconnu',
            },
            match (count($jails)) {
                0 => 'aucune jail activée',
                1 => '1 jail activée',
                default => count($jails).' jails activées',
            },
        ];
        foreach (array_filter((array) ($state['config_errors'] ?? []), 'is_string') as $error) {
            $status[] = "Erreur de configuration : {$error}";
        }

        return ['status' => implode(' · ', $status), 'rows' => array_map(self::jailRow(...), $jails)];
    }

    private static function jailRow(array $jail): array
    {
        $actions = [];
        foreach ((array) ($jail['actions'] ?? []) as $action) {
            if (is_array($action) && is_scalar($action['name'] ?? null)) {
                $details = array_filter([$action['port'] ?? null, $action['protocol'] ?? null], 'is_scalar');
                $actions[] = $action['name'].($details === [] ? '' : ' ('.implode(', ', $details).')');
            }
        }

        return [
            'name' => self::text($jail['name'] ?? null),
            'backend' => self::text($jail['backend'] ?? null),
            'bantime' => self::text($jail['bantime'] ?? null),
            'findtime' => self::text($jail['findtime'] ?? null),
            'maxretry' => self::text($jail['maxretry'] ?? null),
            'actions' => implode(', ', $actions) ?: null,
            'ignoreip' => implode(', ', array_filter((array) ($jail['ignoreip'] ?? []), 'is_scalar')) ?: null,
            'logpaths' => implode(', ', array_filter((array) ($jail['logpaths'] ?? []), 'is_scalar')) ?: null,
        ];
    }

    private static function text(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /** Why the section says nothing at all, if so. */
    private static function blocked(?ServerSection $network): ?string
    {
        return match (true) {
            $network === null => self::TOO_OLD,
            $network->data === null => 'Non lu lors du dernier envoi : '.($network->error ?? 'erreur inconnue'),
            default => null,
        };
    }

    private static function notRead(mixed $error): string
    {
        return match (true) {
            $error === 'not_installed' => 'Non installé',
            is_scalar($error) && filled($error) => "Non lu : {$error}",
            default => 'Non lu',
        };
    }

    private static function ruleLabel(int $count): string
    {
        return match ($count) {
            0 => 'Aucune règle',
            1 => '1 règle',
            default => "{$count} règles",
        };
    }
}
