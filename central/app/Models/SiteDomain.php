<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One host name served by one vhost: derived from `sites` at every inventory, never edited. */
class SiteDomain extends Model
{
    public const ROLE_SERVER_NAME = 'server_name';
    public const ROLE_ALIAS = 'alias';

    protected $fillable = ['site_id', 'server_id', 'domain', 'role'];

    /**
     * Domain (lower-cased) => role. A name that is both the ServerName and an alias keeps `server_name`.
     *
     * @param  array<int, mixed>  $aliases
     * @return array<string, string>
     */
    public static function namesFor(?string $serverName, array $aliases): array
    {
        $names = [];
        $add = function (mixed $value, string $role) use (&$names): void {
            $domain = mb_strtolower(trim((string) $value));
            if ($domain !== '' && ! isset($names[$domain])) {
                $names[$domain] = $role;
            }
        };

        $add($serverName, self::ROLE_SERVER_NAME);
        foreach ($aliases as $alias) {
            $add($alias, self::ROLE_ALIAS);
        }

        return $names;
    }

    /** Every record of the same domain on non-revoked servers (this one included). */
    public function locations(): HasMany
    {
        return $this->hasMany(self::class, 'domain', 'domain')
            ->whereHas('server', fn ($query) => $query->where('status', '!=', Server::STATUS_REVOKED))
            ->orderBy('id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
