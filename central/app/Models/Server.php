<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

class Server extends Model
{
    use HasApiTokens;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REVOKED = 'revoked';

    public const AGENT_ABILITY = 'inventory:write';

    /** A server that has not reported for this long is shown as stale. */
    public const STALE_AFTER_HOURS = 3;

    protected $fillable = [
        'name', 'hostname', 'fqdn', 'machine_id', 'primary_ip', 'os', 'kernel', 'virtualization',
        'apache_version', 'mysql_version', 'agent_version', 'status', 'last_seen_at',
        'last_source_ip', 'notes',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    public function ips(): HasMany
    {
        return $this->hasMany(ServerIp::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function dbUsers(): HasMany
    {
        return $this->hasMany(DbUser::class);
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    public function enrollmentTokens(): HasMany
    {
        return $this->hasMany(EnrollmentToken::class);
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<', now()->subHours(self::STALE_AFTER_HOURS)));
    }

    public function isStale(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subHours(self::STALE_AFTER_HOURS)));
    }

    /** Hostnames and addresses other servers' sites may use to reach this one. */
    public function addresses(): array
    {
        return array_values(array_filter(array_unique(array_merge(
            [$this->hostname, $this->fqdn, $this->primary_ip],
            $this->ips->pluck('address')->all(),
        ))));
    }
}
