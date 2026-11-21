<?php

namespace App\Support;

/**
 * Which firewalls filter incoming traffic on a server, derived at each inventory from the `network`
 * section and UFW, stored in `servers.firewall_engines`. Untrusted agent data: anything malformed counts
 * as "does not filter".
 *
 * An engine filters incoming traffic when its input chain has a drop/reject policy, or a drop/reject rule
 * in that chain or in a chain it jumps to. UFW is an iptables frontend: when it is active, its iptables
 * rules are not counted again, and nft tables written by iptables-nft (base chain named `INPUT`) never
 * count as nftables. Fail2Ban is listed when its service is active with at least one jail, but it does
 * not make a server "protected" on its own.
 */
class FirewallStatus
{
    public const LABELS = [
        'ufw' => 'UFW', 'nftables' => 'nftables', 'iptables' => 'iptables',
        'iptables_legacy' => 'iptables-legacy', 'fail2ban' => 'Fail2Ban',
    ];

    private const BLOCKING = ['drop', 'reject'];

    /** @return list<string>|null null when nothing tells (no network section and UFW state unknown) */
    public static function engines(?array $network, ?string $ufwStatus): ?array
    {
        $ufw = $ufwStatus === 'active';
        if ($network === null) {
            return $ufwStatus === null ? null : ($ufw ? ['ufw'] : []);
        }

        $engines = [];
        if ($ufw) {
            $engines[] = 'ufw';
        }
        if (is_array($network['nft'] ?? null) && self::nftFilters($network['nft'])) {
            $engines[] = 'nftables';
        }
        foreach (['iptables', 'iptables_legacy'] as $key) {
            if (! $ufw && is_array($network[$key] ?? null) && self::iptablesFilters($network[$key])) {
                $engines[] = $key;
            }
        }
        $fail2ban = $network['fail2ban'] ?? null;
        if (is_array($fail2ban) && ($fail2ban['active'] ?? null) === 'active'
            && array_filter((array) ($fail2ban['jails'] ?? []), 'is_array') !== []) {
            $engines[] = 'fail2ban';
        }

        return $engines;
    }

    /** At least one engine other than Fail2Ban filters incoming traffic. */
    public static function protects(?array $engines): ?bool
    {
        return $engines === null ? null : array_diff($engines, ['fail2ban']) !== [];
    }

    /** @return array{label: string, color: string} */
    public static function badge(?array $engines): array
    {
        return match (true) {
            $engines === null => ['label' => 'Inconnu', 'color' => 'gray'],
            self::protects($engines) => ['label' => implode(' + ', self::labels($engines)), 'color' => 'success'],
            $engines !== [] => ['label' => 'Fail2Ban seul', 'color' => 'warning'],
            default => ['label' => 'Aucun', 'color' => 'danger'],
        };
    }

    /** @return list<string> */
    public static function labels(array $engines): array
    {
        return array_values(array_map(fn ($engine) => self::LABELS[$engine] ?? (string) $engine, $engines));
    }

    private static function nftFilters(array $ruleset): bool
    {
        $chains = [];   // "family table" => [chain name => chain body]
        $rules = [];    // "family table" => [chain name => list of rule bodies]
        foreach (is_array($ruleset['nftables'] ?? null) ? $ruleset['nftables'] : [] as $entity) {
            if (! is_array($entity)) {
                continue;
            }
            if (is_array($chain = $entity['chain'] ?? null) && is_string($chain['name'] ?? null)) {
                $chains[self::nftTable($chain)][$chain['name']] = $chain;
            } elseif (is_array($rule = $entity['rule'] ?? null) && is_string($rule['chain'] ?? null)) {
                $rules[self::nftTable($rule)][$rule['chain']][] = $rule;
            }
        }

        foreach ($chains as $table => $tableChains) {
            foreach ($tableChains as $name => $chain) {
                if (($chain['hook'] ?? null) !== 'input' || ($chain['type'] ?? 'filter') !== 'filter' || $name === 'INPUT') {
                    continue;
                }
                if (in_array(strtolower(is_string($chain['policy'] ?? null) ? $chain['policy'] : ''), self::BLOCKING, true)) {
                    return true;
                }
                if (self::reaches((string) $name, fn (string $chain) => self::nftTargets($rules[$table][$chain] ?? []))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{0: list<string>, 1: bool} chains jumped to, and whether a rule drops or rejects */
    private static function nftTargets(array $rules): array
    {
        $targets = [];
        $blocks = false;
        foreach ($rules as $rule) {
            foreach (is_array($rule['expr'] ?? null) ? $rule['expr'] : [] as $expr) {
                if (! is_array($expr)) {
                    continue;
                }
                if (array_key_exists('drop', $expr) || array_key_exists('reject', $expr)) {
                    $blocks = true;
                }
                foreach (['jump', 'goto'] as $verb) {
                    if (is_string($expr[$verb]['target'] ?? null)) {
                        $targets[] = $expr[$verb]['target'];
                    }
                }
            }
        }

        return [$targets, $blocks];
    }

    private static function iptablesFilters(array $families): bool
    {
        foreach (['v4', 'v6'] as $family) {
            $lines = $families[$family] ?? null;
            if (! is_array($lines)) {
                continue;
            }
            $table = null;
            $rules = [];
            foreach ($lines as $line) {
                if (! is_string($line)) {
                    continue;
                }
                if (str_starts_with($line, '*')) {
                    $table = substr($line, 1);
                } elseif ($table === 'filter' && preg_match('/^:INPUT\s+(DROP|REJECT)\b/', $line)) {
                    return true;
                } elseif ($table === 'filter' && preg_match('/^-A\s+(\S+)\s*(.*)$/', $line, $match)) {
                    $rules[$match[1]][] = $match[2];
                }
            }
            $found = self::reaches('INPUT', function (string $chain) use ($rules) {
                $targets = [];
                $blocks = false;
                foreach ($rules[$chain] ?? [] as $rule) {
                    if (preg_match('/(?:^|\s)-[jg]\s+(\S+)/', $rule, $match)) {
                        if (in_array(strtolower($match[1]), self::BLOCKING, true)) {
                            $blocks = true;
                        } else {
                            $targets[] = $match[1];
                        }
                    }
                }

                return [$targets, $blocks];
            });
            if ($found) {
                return true;
            }
        }

        return false;
    }

    /** Walks the chains reachable from $start; $inspect gives [jump targets, drops or rejects]. */
    private static function reaches(string $start, callable $inspect): bool
    {
        $todo = [$start];
        $seen = [];
        while ($todo !== []) {
            $chain = array_pop($todo);
            if (isset($seen[$chain])) {
                continue;
            }
            $seen[$chain] = true;
            [$targets, $blocks] = $inspect($chain);
            if ($blocks) {
                return true;
            }
            array_push($todo, ...$targets);
        }

        return false;
    }

    private static function nftTable(array $body): string
    {
        return (is_scalar($body['family'] ?? null) ? $body['family'] : '?').' '.(is_scalar($body['table'] ?? null) ? $body['table'] : '?');
    }
}
