<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServerIp extends Model
{
    public $timestamps = false;

    protected $fillable = ['server_id', 'interface', 'address', 'prefix'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
