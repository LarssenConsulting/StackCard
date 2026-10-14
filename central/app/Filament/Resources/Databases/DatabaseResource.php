<?php

namespace App\Filament\Resources\Databases;

use App\Filament\Resources\Databases\Pages\ListDatabases;
use App\Filament\Resources\Databases\Pages\ViewDatabase;
use App\Filament\Resources\Databases\Schemas\DatabaseInfolist;
use App\Filament\Resources\Databases\Tables\DatabasesTable;
use App\Models\Database;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/** Read-only: databases are what the agents report. */
class DatabaseResource extends Resource
{
    protected static ?string $model = Database::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCircleStack;

    protected static ?string $modelLabel = 'base de données';

    protected static ?string $pluralModelLabel = 'bases de données';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return DatabaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DatabasesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDatabases::route('/'),
            'view' => ViewDatabase::route('/{record}'),
        ];
    }
}
