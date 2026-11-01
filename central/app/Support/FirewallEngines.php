<?php

namespace App\Support;

use App\Models\ServerSection;

/**
 * What the `network` section says about nftables and iptables, ready for a table.
 * `null` in the data means "not read", `[]` means "read, nothing there": they are told apart.
 */
class FirewallEngines
{
    private const TOO_OLD = 'Non remonté : il faut un agent 0.5.1 ou plus récent';

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
