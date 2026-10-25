<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A Node.js process listening on a server, with or without a vhost in front of it. */
class NodeApp extends Model
{
    /** Addresses that only accept connections from the server itself. */
    public const LOOPBACK = ['127.0.0.1', '::1', 'localhost'];

    /** Addresses meaning "every interface", as `ss` prints them. */
    public const WILDCARD = ['0.0.0.0', '::', '*'];

    protected $fillable = [
        'server_id', 'name', 'version', 'node_version', 'listen', 'user', 'group', 'app_dir',
        'process_manager', 'technologies',
    ];

    protected function casts(): array
    {
        return [
            'listen' => 'array',
            'technologies' => 'array',
        ];
    }

    public function ports(): array
    {
        return array_values(array_unique(array_column($this->listen, 'port')));
    }

    /** Reachable from the network, not only through a local reverse proxy. */
    public function isExposed(): bool
    {
        return collect($this->listen)->contains(fn (array $socket) => ! in_array($socket['address'], self::LOOPBACK, true));
    }

    public function label(): string
    {
        return trim(($this->name ?? basename((string) $this->app_dir)).' '.($this->version ?? ''));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
