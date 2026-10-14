<?php

namespace App\Models;

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
        'owner_user', 'owner_group', 'process_manager', 'config_file', 'partial',
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'https' => 'boolean',
            'partial' => 'boolean',
            'cert_expires_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function dbRefs(): HasMany
    {
        return $this->hasMany(SiteDbRef::class);
    }
}
