<?php

namespace App\Support;

/**
 * Turns the JSON of `nft -j list ruleset` into table rows. The JSON lists tables, chains, sets
 * and rules flat: they are grouped here. Whatever is not recognised is shown as JSON rather
 * than dropped. The input comes from an agent: untrusted.
 */
class NftRuleset
{
    private const VERDICTS = ['accept', 'drop', 'reject', 'return', 'continue', 'queue', 'jump', 'goto', 'masquerade', 'snat', 'dnat', 'redirect'];

    /** @return list<array{table: ?string, chain: ?string, conditions: ?string, action: ?string, comment: ?string}> */
    public static function rows(array $ruleset): array
    {
        $tables = [];
        $others = [];
        foreach (self::entities($ruleset) as $entity) {
            $kind = is_array($entity) ? array_key_first($entity) : null;
            $body = $kind !== null ? $entity[$kind] : null;
            if ($kind === 'metainfo') {
                continue;
            }
            if (! is_array($body) || ! in_array($kind, ['table', 'chain', 'rule', 'set', 'map'], true)) {
                $others[] = self::row(null, null, self::json($entity));

                continue;
            }
            $table = self::value($body['family'] ?? '?').' '.self::value(($kind === 'table' ? $body['name'] ?? null : $body['table'] ?? null) ?? '?');
            $tables[$table] ??= ['sets' => [], 'chains' => []];
            match ($kind) {
                'table' => null,
                'set', 'map' => $tables[$table]['sets'][] = self::set($table, $kind, $body),
                'chain' => $tables[$table]['chains'][self::value($body['name'] ?? '?')]['head'] = $body,
                'rule' => $tables[$table]['chains'][self::value($body['chain'] ?? '?')]['rules'][] = self::rule($table, $body),
            };
        }

        $rows = [];
        foreach ($tables as $name => $table) {
            if ($table['sets'] === [] && $table['chains'] === []) {
                $rows[] = self::row($name, null, 'Table sans chaîne');
            }
            array_push($rows, ...$table['sets']);
            foreach ($table['chains'] as $chainName => $chain) {
                $policy = self::policy($name, (string) $chainName, $chain['head'] ?? []);
                if ($policy !== null) {
                    $rows[] = $policy;
                } elseif (empty($chain['rules'])) {
                    $rows[] = self::row($name, (string) $chainName, 'Chaîne sans règle');
                }
                array_push($rows, ...($chain['rules'] ?? []));
            }
        }

        return [...$rows, ...$others];
    }

    public static function ruleCount(array $ruleset): int
    {
        return count(array_filter(self::entities($ruleset), fn ($entity) => is_array($entity) && isset($entity['rule'])));
    }

    private static function entities(array $ruleset): array
    {
        return is_array($ruleset['nftables'] ?? null) ? $ruleset['nftables'] : [];
    }

    private static function row(?string $table, ?string $chain, ?string $conditions, ?string $action = null, ?string $comment = null): array
    {
        return compact('table', 'chain', 'conditions', 'action', 'comment');
    }

    /** The row of a base chain, the one holding a default policy; null for a plain chain. */
    private static function policy(string $table, string $name, array $chain): ?array
    {
        $details = [];
        foreach (['type', 'hook', 'prio'] as $key) {
            if (isset($chain[$key])) {
                $details[] = ($key === 'prio' ? 'priority' : $key).' '.self::value($chain[$key]);
            }
        }
        if ($details === [] && ! isset($chain['policy'])) {
            return null;
        }
        $text = 'Politique par défaut'.($details === [] ? '' : ' ('.implode(', ', $details).')');

        return self::row($table, $name, $text, isset($chain['policy']) ? self::value($chain['policy']) : null);
    }

    private static function set(string $table, string $kind, array $set): array
    {
        $elements = isset($set['elem']) ? ' : '.self::value((array) $set['elem']) : '';

        return self::row($table, "{$kind} ".self::value($set['name'] ?? '?'), 'type '.self::value($set['type'] ?? '?').$elements);
    }

    private static function rule(string $table, array $rule): array
    {
        $conditions = [];
        $actions = [];
        foreach ((array) ($rule['expr'] ?? []) as $expression) {
            if (! is_array($expression) || $expression === []) {
                $conditions[] = self::value($expression);

                continue;
            }
            $name = array_key_first($expression);
            if ($name === 'counter') {
                continue;
            }
            if (in_array($name, self::VERDICTS, true)) {
                $actions[] = self::statement($name, $expression[$name]);
            } else {
                $conditions[] = self::statement($name, $expression[$name]);
            }
        }

        return self::row(
            $table,
            self::value($rule['chain'] ?? '?'),
            implode(' ', $conditions) ?: null,
            implode(' ', $actions) ?: null,
            is_string($rule['comment'] ?? null) ? $rule['comment'] : null,
        );
    }

    private static function statement(string|int $name, mixed $body): string
    {
        return match (true) {
            $name === 'match' && is_array($body) => self::match($body),
            in_array($name, ['jump', 'goto'], true) && is_array($body) => "{$name} ".self::value($body['target'] ?? '?'),
            default => $body === null ? (string) $name : "{$name} ".self::value($body),
        };
    }

    private static function match(array $match): string
    {
        $operator = self::value($match['op'] ?? '==');
        $operator = in_array($operator, ['==', 'in'], true) ? '' : " {$operator}";

        return self::value($match['left'] ?? '?').$operator.' '.self::value($match['right'] ?? '?');
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            is_scalar($value) => (string) $value,
            array_is_list($value) => '{ '.implode(', ', array_map(self::value(...), $value)).' }',
            is_array($value['set'] ?? null) => self::value($value['set']),
            isset($value['payload']['protocol'], $value['payload']['field']) => self::value($value['payload']['protocol']).' '.self::value($value['payload']['field']),
            isset($value['meta']['key']) => 'meta '.self::value($value['meta']['key']),
            isset($value['ct']['key']) => 'ct '.self::value($value['ct']['key']),
            isset($value['prefix']['addr'], $value['prefix']['len']) => self::value($value['prefix']['addr']).'/'.self::value($value['prefix']['len']),
            is_array($value['range'] ?? null) && count($value['range']) === 2 => implode('-', array_map(self::value(...), array_values($value['range']))),
            isset($value['elem']['val']) => self::value($value['elem']['val']),
            default => self::json($value),
        };
    }

    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }
}
