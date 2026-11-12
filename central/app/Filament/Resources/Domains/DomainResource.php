<?php

namespace App\Filament\Resources\Domains;

use App\Filament\Resources\Domains\Pages\ListDomains;
use App\Filament\Resources\Domains\Tables\DomainsTable;
use App\Models\Server;
use App\Models\SiteDomain;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only: one row per distinct host name, with every server and vhost serving it, derived at every inventory. */
class DomainResource extends Resource
{
    protected static ?string $model = SiteDomain::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGlobeAlt;

    protected static ?string $modelLabel = 'domaine';

    protected static ?string $pluralModelLabel = 'domaines';

    protected static ?string $recordTitleAttribute = 'domain';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // One representative record (the oldest) per domain, among non-revoked servers.
        return parent::getEloquentQuery()->whereIn('site_domains.id', fn ($query) => $query
            ->selectRaw('MIN(site_domains.id)')
            ->from('site_domains')
            ->join('servers', 'servers.id', '=', 'site_domains.server_id')
            ->where('servers.status', '!=', Server::STATUS_REVOKED)
            ->groupBy('site_domains.domain'));
    }

    public static function table(Table $table): Table
    {
        return DomainsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDomains::route('/'),
        ];
    }
}
