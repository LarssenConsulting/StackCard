<?php

namespace App\Actions;

use App\Models\EnrollmentToken;
use App\Models\Server;
use Illuminate\Support\Str;

/**
 * Issue a single-use enrollment token for a server. Only its SHA-256 is
 * stored; the plain value is returned once, to be shown to the admin.
 */
class CreateEnrollmentToken
{
    public function __invoke(Server $server): string
    {
        $plain = Str::random(48);

        $server->enrollmentTokens()->whereNull('used_at')->delete();
        $server->enrollmentTokens()->create([
            'token_hash' => EnrollmentToken::hash($plain),
            'expires_at' => now()->addHours(EnrollmentToken::VALID_HOURS),
        ]);

        // Issuing a token for a revoked server is the admin re-admitting it.
        if ($server->status === Server::STATUS_REVOKED) {
            $server->update(['status' => Server::STATUS_PENDING]);
        }

        return $plain;
    }
}
