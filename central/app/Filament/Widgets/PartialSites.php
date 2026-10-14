<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Sites\SiteResource;
use App\Models\Site;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class PartialSites extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Sites à analyse partielle (variables ou macros Apache non résolues)')
            ->query(fn () => Site::query()->where('partial', true)->with('server'))
            ->defaultSort('server_name')
            ->paginated([10])
            ->emptyStateHeading('Toutes les configurations Apache ont été lues entièrement.')
            ->recordUrl(fn (Site $record) => SiteResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('server_name')->label('Domaine')->placeholder('(sans ServerName)'),
                TextColumn::make('server.name')->label('Serveur'),
                TextColumn::make('config_file')->label('Fichier de configuration'),
            ]);
    }
}
