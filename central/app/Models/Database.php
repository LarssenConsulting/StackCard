<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Database extends Model
{
    protected $table = 'db_databases';

    protected $fillable = ['server_id', 'name', 'size_bytes'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function siteDbRefs(): HasMany
    {
        return $this->hasMany(SiteDbRef::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DbGrant::class);
    }

    /** Databases that no inventoried site is linked to. */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->whereDoesntHave('siteDbRefs');
    }
}
