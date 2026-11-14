<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One state of one detailed section (packages, kernel, ...) of a server. A new row is added only
 * when the state changes; an unchanged state just moves last_seen_at forward.
 */
class ServerSection extends Model
{
    /** Same names and order as SECTION_NAMES in the agent (agent/discover_agent/sections/core.py). */
    public const NAMES = [
        'packages', 'apt_sources', 'apt_updates', 'kernel', 'restart_needed', 'kernel_surface', 'network', 'php',
        'apache', 'site_dependencies', 'node_apps', 'docker', 'persistence', 'integrity', 'code_patterns',
    ];

    /** Largest `data` kept for one section, as JSON (the agent applies the same cap). */
    public const MAX_BYTES = 4194304;

    protected $fillable = [
        'server_id', 'section', 'collected_at', 'sha256', 'error', 'source_errors', 'data',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime', 'first_seen_at' => 'datetime', 'last_seen_at' => 'datetime',
            'source_errors' => 'array', 'data' => 'array',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
