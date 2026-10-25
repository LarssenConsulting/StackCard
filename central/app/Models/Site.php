<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    public const TYPES = [
        'php-fpm' => 'PHP-FPM',
        'php-mod' => 'mod_php',
        'node' => 'Node / JS',
        'proxy' => 'Proxy externe',
        'static' => 'Statique',
    ];

    protected $fillable = [
        'server_id', 'server_name', 'aliases', 'port', 'https', 'cert_expires_at', 'type',
        'document_root', 'app_dir', 'upstream', 'php_version', 'system_user', 'system_group',
        'owner_user', 'owner_group', 'process_manager', 'config_file', 'partial', 'technologies',
        'node_app_id',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'technologies' => 'array',
            'https' => 'boolean',
            'partial' => 'boolean',
            'cert_expires_at' => 'datetime',
        ];
    }

    /**
     * One vhost per site name and server: the HTTPS one, else the first. Apache (and Virtualmin)
     * declare a vhost per port, so the same site would otherwise be listed for 80 and 443.
     * Vhosts without ServerName are all kept.
     */
    public function scopePrimaryPerName(Builder $query): Builder
    {
        return $query->whereNotExists(fn ($other) => $other->selectRaw('1')
            ->from('sites as other')
            ->whereColumn('other.server_id', 'sites.server_id')
            ->whereColumn('other.server_name', 'sites.server_name')
            ->where(fn ($preferred) => $preferred->whereColumn('other.https', '>', 'sites.https')
                ->orWhere(fn ($first) => $first->whereColumn('other.https', 'sites.https')
                    ->whereColumn('other.id', '<', 'sites.id'))));
    }

    /** Ports of every vhost of this site name on this server, e.g. "80, 443". */
    public function ports(): string
    {
        if ($this->server_name === null) {
            return (string) $this->port;
        }

        return self::query()
            ->where('server_id', $this->server_id)
            ->where('server_name', $this->server_name)
            ->orderBy('port')
            ->pluck('port')
            ->unique()
            ->implode(', ');
    }

    /** ["Laravel 12.3.0", "Filament"] from the [{name, version}] list an agent reports. */
    public static function technologyLabels(?array $technologies): array
    {
        return array_map(
            fn (array $tech) => trim($tech['name'].' '.($tech['version'] ?? '')),
            $technologies ?? [],
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function nodeApp(): BelongsTo
    {
        return $this->belongsTo(NodeApp::class);
    }

    public function dbRefs(): HasMany
    {
        return $this->hasMany(SiteDbRef::class);
    }
}
