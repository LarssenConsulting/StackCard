<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class StaleServers extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Serveurs silencieux (aucun envoi depuis plus de '.Server::STALE_AFTER_HOURS.' h)')
            ->query(fn () => Server::query()->stale())
            ->defaultSort('last_seen_at')
            ->paginated(false)
            ->emptyStateHeading('Tous les serveurs actifs ont envoyé un inventaire récemment.')
            ->recordUrl(fn (Server $record) => ServerResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name')->label('Serveur'),
                TextColumn::make('primary_ip')->label('IP')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Dernier envoi')->since()->placeholder('jamais'),
                TextColumn::make('last_source_ip')->label('Dernière IP source')->placeholder('—'),
            ]);
    }
}
