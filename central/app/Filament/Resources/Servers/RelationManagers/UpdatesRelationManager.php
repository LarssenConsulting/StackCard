<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Filament\Resources\Servers\Tables\ServersTable;
use App\Models\ServerUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class UpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    protected static ?string $title = 'Mises à jour';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->description(fn () => self::summary($this->getOwnerRecord()))
            ->defaultSort('package')
            ->emptyStateHeading('Aucune mise à jour en attente connue')
            ->columns([
                TextColumn::make('package')->label('Paquet')->searchable()->sortable()
                    ->description(fn (ServerUpdate $record) => $record->arch),
                TextColumn::make('installed_version')->label('Installé')->placeholder('—'),
                TextColumn::make('candidate_version')->label('Disponible'),
                TextColumn::make('security')
                    ->label('Sécurité')
                    ->badge()
                    ->state(fn (ServerUpdate $record) => $record->security ? 'sécurité' : 'normale')
                    ->color(fn (ServerUpdate $record) => $record->security ? 'danger' : 'gray'),
                TextColumn::make('origins')
                    ->label('Origines')
                    ->state(fn (ServerUpdate $record) => $record->origins ?? [])
                    ->listWithLineBreaks()
                    ->placeholder('—'),
            ])
            ->filters([
                TernaryFilter::make('security')
                    ->label('Sécurité')
                    ->trueLabel('Sécurité seulement')
                    ->falseLabel('Hors sécurité')
                    ->queries(
                        true: fn ($query) => $query->where('security', true),
                        false: fn ($query) => $query->where('security', false),
                    ),
            ]);
    }

    private static function summary($server): string
    {
        $parts = ['État : '.ServersTable::updatesLabel($server)];
        $parts[] = $server->apt_cache_updated_at
            ? 'cache APT : '.$server->apt_cache_updated_at->diffForHumans()
            : 'cache APT : date inconnue';
        if (filled($server->updates_error)) {
            $parts[] = 'erreur : '.$server->updates_error;
        }
        if ($server->reboot_required) {
            $parts[] = 'redémarrage requis';
            $rebootPackages = $server->latestValidSection('kernel')?->data['reboot_required_pkgs'] ?? null;
            if (is_array($rebootPackages) && $rebootPackages !== []) {
                $parts[] = 'paquets à l\'origine du redémarrage : '.implode(', ', array_filter($rebootPackages, 'is_string'));
            }
        }
        $held = $server->latestValidSection('apt_updates')?->data['held'] ?? null;
        if (is_array($held) && $held !== []) {
            $parts[] = 'paquets retenus : '.implode(', ', array_filter($held, 'is_string'));
        }

        return implode(' · ', $parts);
    }
}
