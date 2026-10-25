<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/** The latest published agent version, read from the release's version.txt. */
class AgentRelease
{
    private const CACHE_KEY = 'discover.agent_latest_version';

    public static function latest(): ?string
    {
        $url = config('discover.agent_version_url');
        if (! $url) {
            return null;
        }

        $cached = Cache::get(self::CACHE_KEY);
        if ($cached !== null) {
            return $cached ?: null;
        }

        $version = self::fetch($url);
        // An unreachable release is retried sooner than a known version is refreshed.
        Cache::put(self::CACHE_KEY, $version ?? '', $version ? now()->addHours(6) : now()->addHour());

        return $version;
    }

    public static function isOutdated(?string $installed): bool
    {
        $latest = self::latest();

        return $installed !== null && $latest !== null && version_compare($installed, $latest, '<');
    }

    /** "0.1.0 (0.2.0 disponible)" when a newer agent is published. */
    public static function describe(?string $installed): ?string
    {
        if ($installed === null) {
            return null;
        }

        return self::isOutdated($installed) ? $installed.' ('.self::latest().' disponible)' : $installed;
    }

    private static function fetch(string $url): ?string
    {
        try {
            $response = Http::timeout(3)->get($url);
        } catch (ConnectionException) {
            return null;
        }
        $version = trim($response->body());

        return $response->successful() && preg_match('/^\d+(\.\d+){1,3}$/', $version) ? $version : null;
    }
}
