<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Indented JSON with coloured tokens, for display. The data comes from an agent: every token
 * is escaped, and what lies between tokens is only JSON punctuation and whitespace.
 */
class JsonHighlighter
{
    /** Beyond this, the browser would have to lay out hundreds of thousands of spans: plain text. */
    public const MAX_HIGHLIGHTED_BYTES = 524288;

    private const FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR;

    // Possessive quantifiers: a very long string must not exhaust PCRE's backtracking.
    private const TOKEN = '/("(?:[^"\\\\]++|\\\\.)*+")(\s*+:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/';

    public static function html(mixed $data): HtmlString
    {
        // Two spaces per level instead of PHP's four. A JSON string holds no raw newline, so only indentation matches.
        $json = (string) preg_replace_callback('/^ +/m', fn (array $m) => substr($m[0], 0, (int) (strlen($m[0]) / 2)), (string) json_encode($data, self::FLAGS));
        if (strlen($json) > self::MAX_HIGHLIGHTED_BYTES) {
            return new HtmlString(e($json));
        }

        $html = preg_replace_callback(self::TOKEN, self::token(...), $json);

        return new HtmlString($html ?? e($json));
    }

    private static function token(array $match): string
    {
        if (($match[1] ?? '') !== '') {
            return isset($match[2])
                ? self::span('key', $match[1]).$match[2]
                : self::span('string', $match[1]);
        }

        return self::span(is_numeric($match[0]) ? 'number' : 'literal', $match[0]);
    }

    private static function span(string $kind, string $text): string
    {
        return '<span class="sc-json-'.$kind.'">'.e($text).'</span>';
    }
}
