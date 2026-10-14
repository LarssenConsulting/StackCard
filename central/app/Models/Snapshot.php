<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Snapshot extends Model
{
    public const RETENTION_DAYS = 30;

    protected $fillable = [
        'server_id', 'received_at', 'source_ip', 'schema_version', 'payload', 'error_count', 'warnings',
    ];

    protected function casts(): array
    {
        return ['received_at' => 'datetime', 'payload' => 'array', 'warnings' => 'array'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
