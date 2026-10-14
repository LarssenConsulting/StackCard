<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Sites\SiteResource;
use App\Filament\Resources\Sites\Tables\SitesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;

class SitesRelationManager extends RelationManager
{
    protected static string $relationship = 'sites';

    protected static ?string $title = 'Sites';

    protected static ?string $relatedResource = SiteResource::class;

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return SitesTable::configure($table, withServer: false);
    }
}
