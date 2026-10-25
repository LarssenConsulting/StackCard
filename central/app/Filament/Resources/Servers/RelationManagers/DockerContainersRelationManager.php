<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\DockerContainer;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DockerContainersRelationManager extends RelationManager
{
    protected static string $relationship = 'dockerContainers';

    protected static ?string $title = 'Conteneurs Docker';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sites'))
            ->defaultSort('name')
            ->emptyStateHeading('Aucun conteneur Docker sur ce serveur')
            ->columns([
                TextColumn::make('name')
                    ->label('Conteneur')
                    ->description(fn (DockerContainer $record) => $record->compose_project
                        ? trim("Compose {$record->label()} · {$record->compose_dir}", ' ·')
                        : null)
                    ->searchable(['name', 'compose_project', 'compose_service', 'image'])
                    ->sortable(),
                TextColumn::make('image')
                    ->label('Image')
                    ->description(fn (DockerContainer $record) => $record->image_version)
                    ->placeholder('—'),
                TextColumn::make('state')
                    ->label('État')
                    ->badge()
                    ->state(fn (DockerContainer $record) => $record->stateLabel())
                    ->color(fn (DockerContainer $record) => match ($record->state) {
                        'running' => 'success',
                        'restarting', 'dead' => 'danger',
                        default => 'gray',
                    })
                    ->description(fn (DockerContainer $record) => $record->started_at && $record->state === 'running'
                        ? 'depuis '.$record->started_at->diffForHumans(syntax: true)
                        : null),
                TextColumn::make('ports')
                    ->label('Ports publiés')
                    ->state(fn (DockerContainer $record) => array_map(
                        fn (array $port) => (str_contains($port['address'], ':')
                            ? "[{$port['address']}]:{$port['port']}"
                            : "{$port['address']}:{$port['port']}")
                            .($port['container_port'] ? " → {$port['container_port']}/".($port['protocol'] ?? 'tcp') : ''),
                        $record->ports,
                    ))
                    ->listWithLineBreaks()
                    ->placeholder('Aucun'),
                TextColumn::make('exposure')
                    ->label('Accès')
                    ->badge()
                    ->state(fn (DockerContainer $record) => match (true) {
                        $record->ports === [] => null,
                        $record->isExposed() => 'Réseau',
                        default => 'Local uniquement',
                    })
                    ->color(fn (DockerContainer $record) => $record->isExposed() ? 'warning' : 'success')
                    ->tooltip(fn (DockerContainer $record) => $record->isExposed()
                        ? 'Publié sur une adresse joignable depuis le réseau, sans passer par Apache.'
                        : 'Seul ce serveur peut s’y connecter (derrière un proxy Apache).')
                    ->placeholder('—'),
                TextColumn::make('restart_policy')->label('Redémarrage')->placeholder('—'),
                TextColumn::make('sites')
                    ->label('Sites qui y renvoient')
                    ->state(fn (DockerContainer $record) => $record->sites->pluck('server_name')->filter()->unique()->sort()->values()->all())
                    ->listWithLineBreaks()
                    ->placeholder('Aucun'),
            ]);
    }
}
