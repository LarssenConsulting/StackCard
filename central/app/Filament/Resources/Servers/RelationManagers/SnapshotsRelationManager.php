<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\Snapshot;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class SnapshotsRelationManager extends RelationManager
{
    protected static string $relationship = 'snapshots';

    protected static ?string $title = 'Historique des envois';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('received_at', 'desc')
            ->columns([
                TextColumn::make('received_at')->label('Reçu')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('source_ip')->label('IP source'),
                TextColumn::make('payload.agent_version')->label('Agent'),
                TextColumn::make('error_count')
                    ->label('Erreurs de collecte')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('details')
                    ->label('Détail')
                    ->state(fn (Snapshot $record) => array_merge(
                        array_map(fn ($e) => "[{$e['collector']}] {$e['message']}", $record->payload['errors'] ?? []),
                        $record->warnings ?? [],
                    ))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->wrap()
                    ->placeholder('—'),
            ]);
    }
}
