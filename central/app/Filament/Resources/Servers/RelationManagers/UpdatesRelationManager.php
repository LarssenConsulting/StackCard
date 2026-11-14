<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\ServerUpdate;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

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

    private static function summary($server): HtmlString
    {
        return new HtmlString(view('filament.server-updates.summary', self::summaryData($server))->render());
    }

    /** What the tab header shows; the view escapes every string. */
    public static function summaryData($server): array
    {
        $state = $server->updatesState();
        $rebootPackages = $server->latestValidSection('kernel')?->data['reboot_required_pkgs'] ?? null;
        $held = $server->latestValidSection('apt_updates')?->data['held'] ?? null;

        return [
            'freshness' => match ($state) {
                'ok' => ['label' => 'À jour', 'color' => 'success'],
                'stale' => ['label' => 'Périmées', 'color' => 'warning'],
                default => ['label' => 'Inconnues', 'color' => 'gray'],
            },
            'cache' => $server->apt_cache_updated_at
                ? 'cache APT '.$server->apt_cache_updated_at->diffForHumans()
                : 'âge du cache APT inconnu',
            'pending' => $state === 'unknown' ? null : (int) $server->updates_count,
            'security' => (int) $server->security_updates_count,
            'reboot' => match ($server->reboot_required) {
                true => ['label' => 'Requis', 'color' => 'warning'],
                false => ['label' => 'Non requis', 'color' => 'gray'],
                default => ['label' => 'Inconnu', 'color' => 'gray'],
            },
            'rebootPackages' => $server->reboot_required && is_array($rebootPackages)
                ? array_values(array_filter($rebootPackages, 'is_string')) : [],
            'held' => is_array($held) ? array_values(array_filter($held, 'is_string')) : [],
            'error' => filled($server->updates_error) ? (string) $server->updates_error : null,
        ];
    }
}
