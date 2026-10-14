<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DbGrant extends Model
{
    public $timestamps = false;

    protected $fillable = ['db_user_id', 'database_id', 'privileges', 'global'];

    protected function casts(): array
    {
        return ['privileges' => 'array', 'global' => 'boolean'];
    }

    public function dbUser(): BelongsTo
    {
        return $this->belongsTo(DbUser::class);
    }

    public function database(): BelongsTo
    {
        return $this->belongsTo(Database::class);
    }
}
