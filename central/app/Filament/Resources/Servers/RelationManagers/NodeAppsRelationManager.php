<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\NodeApp;
use App\Models\Site;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class NodeAppsRelationManager extends RelationManager
{
    protected static string $relationship = 'nodeApps';

    protected static ?string $title = 'Applications Node';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sites'))
            ->defaultSort('name')
            ->emptyStateHeading('Aucune application Node à l’écoute sur ce serveur')
            ->columns([
                TextColumn::make('name')
                    ->label('Application')
                    ->state(fn (NodeApp $record) => $record->label())
                    ->description(fn (NodeApp $record) => $record->app_dir)
                    ->searchable(['name', 'app_dir'])
                    ->sortable(),
                TextColumn::make('listen')
                    ->label('Écoute')
                    ->state(fn (NodeApp $record) => array_map(
                        fn (array $socket) => str_contains($socket['address'], ':')
                            ? "[{$socket['address']}]:{$socket['port']}"
                            : "{$socket['address']}:{$socket['port']}",
                        $record->listen,
                    ))
                    ->listWithLineBreaks(),
                TextColumn::make('exposure')
                    ->label('Accès')
                    ->badge()
                    ->state(fn (NodeApp $record) => $record->isExposed() ? 'Réseau' : 'Local uniquement')
                    ->color(fn (NodeApp $record) => $record->isExposed() ? 'warning' : 'success')
                    ->tooltip(fn (NodeApp $record) => $record->isExposed()
                        ? 'Joignable depuis le réseau sans passer par Apache.'
                        : 'Seul ce serveur peut s’y connecter (derrière un proxy Apache).'),
                TextColumn::make('node_version')->label('Node')->placeholder('—'),
                TextColumn::make('technologies')
                    ->label('Technos')
                    ->state(fn (NodeApp $record) => Site::technologyLabels($record->technologies))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('user')
                    ->label('Exécuté par')
                    ->state(fn (NodeApp $record) => trim($record->user.':'.$record->group, ':'))
                    ->placeholder('—'),
                TextColumn::make('process_manager')->label('Gestionnaire')->placeholder('—'),
                TextColumn::make('sites')
                    ->label('Sites qui y renvoient')
                    ->state(fn (NodeApp $record) => $record->sites->pluck('server_name')->filter()->unique()->sort()->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('Aucun (pas de vhost devant)'),
            ]);
    }
}
