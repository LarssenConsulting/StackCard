<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;

/** One place for how a server's state reads in tables and sheets. */
class ServerStatus
{
    public static function label(Server $server): string
    {
        return match (true) {
            $server->status === Server::STATUS_REVOKED => 'Révoqué',
            $server->status === Server::STATUS_PENDING => 'En attente d’enrôlement',
            $server->isStale() => 'Silencieux',
            default => 'Actif',
        };
    }

    public static function color(Server $server): string
    {
        return match (self::label($server)) {
            'Actif' => 'success',
            'Silencieux' => 'warning',
            'Révoqué' => 'danger',
            default => 'gray',
        };
    }
}
