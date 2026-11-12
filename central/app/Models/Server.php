<?php

namespace App\Models;

use App\Support\ServerSlug;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
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
        'apache_version', 'mysql_version', 'docker_version', 'agent_version', 'status', 'last_seen_at',
        'last_source_ip', 'notes', 'ufw_status', 'ufw_logging', 'ufw_default_incoming',
        'ufw_default_outgoing', 'ufw_default_routed', 'slug', 'connect_address', 'connect_port',
        'ansible_excluded',
    ];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'slug_locked_at' => 'datetime', 'ansible_excluded' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (Server $server) {
            if ($server->isDirty('slug') && $server->getOriginal('slug_locked_at') !== null) {
                throw new \LogicException('Le slug d’un serveur figé ne peut plus changer.');
            }
        });

        static::creating(function (Server $server) {
            if (blank($server->slug)) {
                $server->slug = ServerSlug::unique((string) $server->name);
            }
        });
    }

    public function isSlugLocked(): bool
    {
        return $this->slug_locked_at !== null;
    }

    /** Where to reach the server: the manual address, else the one the agent reported. */
    public function effectiveAddress(): ?string
    {
        return $this->connect_address ?: $this->primary_ip;
    }

    /** The agent reports an IP an admin has not accepted yet (`approved_ip` is pinned at the first inventory). */
    public function ipChangePending(): bool
    {
        return $this->primary_ip !== null && $this->approved_ip !== null && $this->primary_ip !== $this->approved_ip;
    }

    /**
     * Where Ansible may connect: the manual address, else the reported IP only while it is the approved
     * one. A compromised agent could otherwise point Semaphore's SSH credentials at another machine.
     */
    public function trustedAddress(): ?string
    {
        if (filled($this->connect_address)) {
            return $this->connect_address;
        }

        return $this->primary_ip !== null && $this->primary_ip === $this->approved_ip ? $this->primary_ip : null;
    }

    /** Labels of ansibleState(), for the admin. */
    public const ANSIBLE_STATES = [
        'included' => 'oui',
        'excluded' => 'exclu',
        'ip_pending' => 'IP à valider',
        'no_address' => 'sans adresse',
        'inactive' => 'non',
    ];

    /** Whether, and why not, the server is in the Ansible inventory. The inventory uses the same rule. */
    public function ansibleState(): string
    {
        return match (true) {
            $this->status !== self::STATUS_ACTIVE => 'inactive',
            $this->ansible_excluded => 'excluded',
            $this->trustedAddress() !== null => 'included',
            $this->ipChangePending() => 'ip_pending',
            default => 'no_address',
        };
    }

    /** Numeric key = id, anything else = slug. */
    public static function findByKey(string $key): self
    {
        return ctype_digit($key)
            ? static::query()->whereKey((int) $key)->firstOrFail()
            : static::query()->where('slug', $key)->firstOrFail();
    }

    public function ips(): HasMany
    {
        return $this->hasMany(ServerIp::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function nodeApps(): HasMany
    {
        return $this->hasMany(NodeApp::class);
    }

    public function dockerContainers(): HasMany
    {
        return $this->hasMany(DockerContainer::class);
    }

    public function firewallRules(): HasMany
    {
        return $this->hasMany(FirewallRule::class);
    }

    public function databases(): HasMany
    {
        return $this->hasMany(Database::class);
    }

    public function dbUsers(): HasMany
    {
        return $this->hasMany(DbUser::class);
    }

    /** The token issued for this server that can still enroll it, if any. */
    public function pendingEnrollmentToken(): ?EnrollmentToken
    {
        return $this->enrollmentTokens()->whereNull('used_at')->where('expires_at', '>', now())->latest('id')->first();
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(Snapshot::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(ServerSection::class);
    }

    /** The newest row of each section, keyed by section name. */
    public function latestSections(): Collection
    {
        $ids = $this->sections()->selectRaw('MAX(id) as id')->groupBy('section')->pluck('id')->all();

        return $this->sections()->whereIn('id', $ids)->get()->keyBy('section');
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
