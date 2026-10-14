<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DbUser extends Model
{
    protected $fillable = ['server_id', 'user', 'host'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DbGrant::class);
    }

    public function getAccountAttribute(): string
    {
        return "{$this->user}@{$this->host}";
    }
}
