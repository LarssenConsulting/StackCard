<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\ServerSection;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

class SectionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sections';

    protected static ?string $title = 'État détaillé';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $this->latestOnly($query))
            ->description(fn () => $this->neverReceivedLabel())
            ->paginated(false)
            ->emptyStateHeading('Aucune section détaillée reçue')
            ->columns([
                TextColumn::make('section')->label('Section')->fontFamily('mono'),
                TextColumn::make('state')
                    ->label('État')
                    ->badge()
                    ->state(fn (ServerSection $record) => self::stateLabel($record))
                    ->color(fn (ServerSection $record) => match (self::stateLabel($record)) {
                        'OK' => 'success',
                        'Non installé' => 'gray',
                        default => 'warning',
                    }),
                TextColumn::make('error')
                    ->label('Erreur')
                    ->state(fn (ServerSection $record) => self::errorMessage($record))
                    ->limit(120)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('collected_at')->label('Collecté')->dateTime('d/m/Y H:i')->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Dernier envoi')->dateTime('d/m/Y H:i'),
            ])
            ->recordActions([
                Action::make('view')
                    ->label('Voir')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (ServerSection $record) => "Section {$record->section}")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Fermer')
                    ->modalWidth('5xl')
                    ->modalContent(function (ServerSection $record): View {
                        // `data` is not selected by the listing query; load it for this row only.
                        $full = ServerSection::query()->findOrFail($record->getKey());

                        return view('filament.server-section.data', [
                            'error' => self::errorMessage($full),
                            'data' => $full->data,
                            'sourceErrors' => $full->source_errors,
                        ]);
                    }),
            ]);
    }

    /** Only the newest row of each section, in the agent's section order, without the heavy `data` column. */
    private function latestOnly(Builder $query): Builder
    {
        $ids = ServerSection::query()
            ->where('server_id', $this->getOwnerRecord()->getKey())
            ->selectRaw('MAX(id) as id')
            ->groupBy('section')
            ->pluck('id')
            ->all();

        $order = 'CASE section';
        foreach (ServerSection::NAMES as $position => $name) {
            $order .= " WHEN '{$name}' THEN {$position}";
        }
        $order .= ' ELSE '.count(ServerSection::NAMES).' END';

        return $query
            ->select(['id', 'server_id', 'section', 'collected_at', 'error', 'source_errors', 'first_seen_at', 'last_seen_at'])
            ->whereIn('id', $ids)
            ->reorder()
            ->orderByRaw($order);
    }

    private function neverReceivedLabel(): ?string
    {
        $received = $this->getOwnerRecord()->sections()->distinct()->pluck('section')->all();
        $missing = array_values(array_diff(ServerSection::NAMES, $received));

        return $missing === [] ? null : 'Jamais reçues : '.implode(', ', $missing);
    }

    /** The failure message, if any: the known sentinels are already said by the state label. */
    private static function errorMessage(ServerSection $record): ?string
    {
        return in_array($record->error, ['timeout', 'not_installed', 'too_large'], true) ? null : $record->error;
    }

    private static function stateLabel(ServerSection $record): string
    {
        return match (true) {
            $record->error === 'not_installed' => 'Non installé',
            $record->error === 'timeout' => 'Délai dépassé',
            $record->error === 'too_large' => 'Trop volumineux',
            filled($record->error) => 'Erreur',
            ! empty($record->source_errors) => 'Partiel',
            default => 'OK',
        };
    }
}
