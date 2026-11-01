<?php

namespace App\Support;

/** Turns the lines of `iptables-save`, as the agent sends them, into table rows. Untrusted input. */
class IptablesRules
{
    private const COMMENT = '/(?:-m comment\s+)?--comment\s+("(?:[^"\\\\]|\\\\.)*"|\S+)\s*/';

    /** @return list<array{family: string, table: ?string, chain: ?string, position: ?int, conditions: ?string, action: ?string, comment: ?string}> */
    public static function rows(array $lines, string $family): array
    {
        $rows = [];
        $table = null;
        $positions = [];
        foreach ($lines as $line) {
            if (! is_string($line) || $line === 'COMMIT') {
                continue;
            }
            if (str_starts_with($line, '*')) {
                $table = substr($line, 1);
            } elseif (preg_match('/^:(\S+)\s+(\S+)/', $line, $policy)) {
                if ($policy[2] !== '-') {  // `-` marks a user chain: it has no policy
                    $rows[] = self::row($family, $table, $policy[1], null, 'Politique par défaut', $policy[2]);
                }
            } elseif (preg_match('/^-A\s+(\S+)\s*(.*)$/', $line, $rule)) {
                $key = "{$table} {$rule[1]}";
                $positions[$key] = ($positions[$key] ?? 0) + 1;
                $rows[] = self::rule($family, $table, $rule[1], $positions[$key], $rule[2]);
            } else {
                $rows[] = self::row($family, $table, null, null, $line);
            }
        }

        return $rows;
    }

    public static function ruleCount(array $lines): int
    {
        return count(array_filter($lines, fn ($line) => is_string($line) && str_starts_with($line, '-A ')));
    }

    private static function rule(string $family, ?string $table, string $chain, int $position, string $text): array
    {
        $comment = null;
        if (preg_match(self::COMMENT, $text, $match)) {
            $comment = trim($match[1], '"');
            $text = (string) preg_replace(self::COMMENT, '', $text, 1);
        }
        $action = null;
        if (preg_match('/(?:^|\s)-[jg]\s+(\S.*)$/', $text, $match, PREG_OFFSET_CAPTURE)) {
            $action = trim($match[1][0]);
            $text = substr($text, 0, $match[0][1]);
        }

        return self::row($family, $table, $chain, $position, trim($text) ?: null, $action, $comment);
    }

    private static function row(string $family, ?string $table, ?string $chain, ?int $position, ?string $conditions, ?string $action = null, ?string $comment = null): array
    {
        return compact('family', 'table', 'chain', 'position', 'conditions', 'action', 'comment');
    }
}
