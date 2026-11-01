<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** One IP address, or a range narrow enough to stay a whitelist: /24 in IPv4 and /48 in IPv6 at most. */
class AllowedIpEntry implements ValidationRule
{
    public const WIDEST_V4 = 24;

    public const WIDEST_V6 = 48;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isValid($value)) {
            $fail('« :input » n’est pas une IP ou une plage autorisée (au plus large /'.self::WIDEST_V4.' en IPv4, /'.self::WIDEST_V6.' en IPv6).');
        }
    }

    public static function isValid(string $value): bool
    {
        [$address, $prefix] = array_pad(explode('/', $value, 2), 2, null);
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
