<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Databases\DatabaseResource;
use App\Filament\Resources\Databases\Tables\DatabasesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class DatabasesRelationManager extends RelationManager
{
    protected static string $relationship = 'databases';

    protected static ?string $title = 'Bases de données';

    protected static ?string $relatedResource = DatabaseResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return DatabasesTable::configure($table, withServer: false);
    }
}
