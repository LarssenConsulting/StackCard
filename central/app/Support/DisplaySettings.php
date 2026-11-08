<?php

namespace App\Support;

use Carbon\CarbonInterface;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * How dates are shown in the panel, one choice for the whole site.
 *
 * Display only: the database and the read API stay in UTC, so `config('app.timezone')` must not change.
 */
class DisplaySettings
{
    public const DEFAULT_TIMEZONE = 'Europe/Paris';

    public const DEFAULT_DATE_FORMAT = 'd/m/Y';

    /** Accepted formats and what they look like. */
    public const DATE_FORMATS = [
        'd/m/Y' => '31/12/2026',
        'd.m.Y' => '31.12.2026',
        'Y-m-d' => '2026-12-31',
        'm/d/Y' => '12/31/2026',
    ];

    private const TIME_FORMAT = 'H:i';

    private const MEMO = 'display-settings';

    public static function timezone(): string
    {
        $stored = self::stored()['timezone'] ?? null;

        return in_array($stored, DateTimeZone::listIdentifiers(), true) ? $stored : self::DEFAULT_TIMEZONE;
    }

    public static function dateFormat(): string
    {
        $stored = self::stored()['date_format'] ?? null;

        return array_key_exists((string) $stored, self::DATE_FORMATS) ? $stored : self::DEFAULT_DATE_FORMAT;
    }

    public static function dateTimeFormat(): string
    {
        return self::dateFormat().' '.self::TIME_FORMAT;
    }

    /** For text built by hand; columns and entries get the same result from `->dateTime()`. */
    public static function formatDateTime(CarbonInterface $date, string $separator = ' '): string
    {
        return $date->copy()->setTimezone(self::timezone())->format(self::dateFormat().$separator.self::TIME_FORMAT);
    }

    public static function save(string $timezone, string $dateFormat): void
    {
        foreach (['timezone' => $timezone, 'date_format' => $dateFormat] as $key => $value) {
            DB::table('settings')->updateOrInsert(['key' => $key], ['value' => $value]);
        }

        self::forget();
    }

    public static function forget(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /**
     * Read once per request, and only when a date is really shown: never while the application
     * boots, so artisan keeps working before the table is migrated.
     *
     * @return array<string, string|null>
     */
    private static function stored(): array
    {
        if (! app()->bound(self::MEMO)) {
            app()->instance(self::MEMO, DB::table('settings')->pluck('value', 'key')->all());
        }

        return app(self::MEMO);
    }
}
