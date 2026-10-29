<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A UFW rule of a server, in the order `ufw status` lists them. */
class FirewallRule extends Model
{
    protected $fillable = ['server_id', 'position', 'destination', 'action', 'source', 'ipv6', 'comment'];

    protected function casts(): array
    {
        return ['ipv6' => 'boolean'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
