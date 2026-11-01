<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An external application allowed to read the inventory. Agents are `Server` tokens and never get
 * this ability: a compromised server must not be able to read the rest of the fleet.
 */
class ApiClient extends Model
{
    use HasApiTokens;

    public const READ_ABILITY = 'inventory:read';

    public const TOKEN_VALID_DAYS = 365;

    protected $fillable = ['name', 'allowed_ips'];

    protected static function booted(): void
    {
        // Sanctum's morph relation has no foreign key: without this, the token would outlive its client.
        static::deleting(fn (ApiClient $client) => $client->tokens()->delete());
    }

    protected function casts(): array
    {
        return ['allowed_ips' => 'array'];
    }

    /** No address allowed means nobody gets in. */
    public function allowsIp(?string $ip): bool
    {
        return $ip !== null && IpUtils::checkIp($ip, $this->allowed_ips ?? []);
    }

    /** A client holds one token: issuing a new one invalidates the previous one. Shown once. */
    public function issueToken(): string
    {
        $this->tokens()->delete();

        return $this->createToken('read', [self::READ_ABILITY], now()->addDays(self::TOKEN_VALID_DAYS))->plainTextToken;
    }

    public function currentToken(): ?PersonalAccessToken
    {
        return $this->tokens()->latest('id')->first();
    }
}
