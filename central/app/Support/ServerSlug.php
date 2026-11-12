<?php

namespace App\Support;

use App\Models\Server;
use Illuminate\Support\Str;

/** Permanent, URL-safe identifier of a server (lowercase words joined by dashes, not only digits). */
class ServerSlug
{
    public const PATTERN = '^(?!\d+$)[a-z0-9]+(?:-[a-z0-9]+)*$';

    public const MAX_LENGTH = 64;

    public static function isValid(string $slug): bool
    {
        return strlen($slug) <= self::MAX_LENGTH && preg_match('/'.self::PATTERN.'/', $slug) === 1;
    }

    public static function from(string $name): string
    {
        $slug = trim(substr(Str::slug($name), 0, self::MAX_LENGTH), '-');
        if ($slug === '') {
            return 'srv';
        }

        return ctype_digit($slug) ? 'srv-'.$slug : $slug;
    }

    public static function unique(string $name, ?int $ignoreId = null): string
    {
        $base = self::from($name);
        $slug = $base;
        for ($n = 2; self::taken($slug, $ignoreId); $n++) {
            $suffix = '-'.$n;
            $slug = rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;
        }

        return $slug;
    }

    private static function taken(string $slug, ?int $ignoreId): bool
    {
        return Server::query()->where('slug', $slug)
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))->exists();
    }
}
