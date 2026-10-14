<?php

namespace App\Filament\Widgets;

use App\Models\Database;
use App\Models\Server;
use App\Models\Site;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class InventoryStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $stale = Server::stale()->count();
        $orphans = Database::orphans()->count();
        $expiring = ExpiringCertificates::expiring()->count();

        return [
            Stat::make('Serveurs', Server::where('status', Server::STATUS_ACTIVE)->count())
                ->description($stale ? "{$stale} silencieux depuis plus de ".Server::STALE_AFTER_HOURS.' h' : 'Tous à jour')
                ->color($stale ? 'warning' : 'success'),
            Stat::make('Sites', Site::count())
                ->description(Site::distinct()->count('server_name').' domaines'),
            Stat::make('Bases de données', Database::count())
                ->description($orphans ? "{$orphans} orphelines" : 'Toutes liées à un site')
                ->color($orphans ? 'warning' : 'success'),
            Stat::make('Certificats', $expiring)
                ->description('expirent dans moins de '.ExpiringCertificates::DAYS.' jours')
                ->color($expiring ? 'danger' : 'success'),
        ];
    }
}
