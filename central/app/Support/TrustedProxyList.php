<?php

namespace App\Support;

/**
 * The reverse proxies whose X-Forwarded-For is believed, from DISCOVER_TRUSTED_PROXIES.
 *
 * `$request->ip()` feeds the read API's allow-list: believing anyone (`*`, or a /0 range) would let
 * any caller forge its address. Whatever is not a precise address or range is dropped, which fails
 * closed: the caller then appears as the proxy itself, and is refused.
 */
class TrustedProxyList
{
    private const WIDEST_V4 = 8;

    private const WIDEST_V6 = 32;

    /** @return list<string> */
    public static function parse(?string $value): array
    {
        $entries = array_map('trim', explode(',', (string) $value));

        return array_values(array_filter($entries, self::isPrecise(...)));
    }

    private static function isPrecise(string $entry): bool
    {
        [$address, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $v4 = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $v6 = ! $v4 && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (! $v4 && ! $v6) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }
        if (! ctype_digit($prefix)) {
            return false;
        }

        return $v4
            ? (int) $prefix >= self::WIDEST_V4 && (int) $prefix <= 32
            : (int) $prefix >= self::WIDEST_V6 && (int) $prefix <= 128;
    }
}
