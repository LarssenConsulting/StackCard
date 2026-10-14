<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Snapshot;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class RecentCollectorErrors extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Erreurs de collecte des dernières 24 h')
            ->query(fn () => Snapshot::query()
                ->where('error_count', '>', 0)
                ->where('received_at', '>=', now()->subDay())
                ->with('server'))
            ->defaultSort('received_at', 'desc')
            ->paginated([10])
            ->emptyStateHeading('Aucune erreur de collecte.')
            ->recordUrl(fn (Snapshot $record) => ServerResource::getUrl('view', ['record' => $record->server_id]))
            ->columns([
                TextColumn::make('server.name')->label('Serveur'),
                TextColumn::make('received_at')->label('Reçu')->since(),
                TextColumn::make('errors')
                    ->label('Erreurs')
                    ->state(fn (Snapshot $record) => array_map(
                        fn ($e) => "[{$e['collector']}] {$e['message']}",
                        $record->payload['errors'] ?? [],
                    ))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->wrap(),
            ]);
    }
}
