<?php

namespace App\Filament\Resources\Updates;

use App\Filament\Resources\Updates\Pages\ListUpdates;
use App\Filament\Resources\Updates\Tables\UpdatesTable;
use App\Models\Server;
use App\Models\ServerUpdate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only fleet view: one row per (package, candidate version), with every server waiting for it. */
class UpdateResource extends Resource
{
    protected static ?string $model = ServerUpdate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPath;

    protected static ?string $modelLabel = 'mise à jour';

    protected static ?string $pluralModelLabel = 'mises à jour';

    protected static ?string $navigationLabel = 'Mises à jour';

    protected static ?string $recordTitleAttribute = 'package';

    protected static ?int $navigationSort = 4;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        // One representative record (the oldest) per package and candidate version, among non-revoked servers.
        return parent::getEloquentQuery()->whereIn('server_updates.id', fn ($query) => $query
            ->selectRaw('MIN(server_updates.id)')
            ->from('server_updates')
            ->join('servers', 'servers.id', '=', 'server_updates.server_id')
            ->where('servers.status', '!=', Server::STATUS_REVOKED)
            ->groupBy('server_updates.package', 'server_updates.candidate_version'));
    }

    public static function table(Table $table): Table
    {
        return UpdatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUpdates::route('/'),
        ];
    }
}
