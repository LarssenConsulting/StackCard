<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnrollmentToken extends Model
{
    public const VALID_HOURS = 24;

    protected $fillable = ['server_id', 'token_hash', 'expires_at', 'used_at'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
