<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One pending APT upgrade of a server. Derived from the latest valid `apt_updates` section and
 * rebuilt at each inventory; never edited by hand.
 */
class ServerUpdate extends Model
{
    protected $fillable = ['server_id', 'package', 'arch', 'installed_version', 'candidate_version', 'security', 'origins'];

    protected function casts(): array
    {
        return ['security' => 'boolean', 'origins' => 'array'];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /** Every pending update of the same package, whatever the server (for the fleet page: filter on candidate_version). */
    public function members(): HasMany
    {
        return $this->hasMany(self::class, 'package', 'package');
    }
}
