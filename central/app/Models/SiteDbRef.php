<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteDbRef extends Model
{
    public const LOCAL = 'local';
    public const REMOTE = 'remote';
    public const UNRESOLVED = 'unresolved';

    protected $fillable = [
        'site_id', 'db_name', 'db_user', 'db_host', 'source', 'database_id', 'db_user_id', 'resolution',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }

    public function dbUser(): BelongsTo
    {
        return $this->belongsTo(DbUser::class);
    }
}
