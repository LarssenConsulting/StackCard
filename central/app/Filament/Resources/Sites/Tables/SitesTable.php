<?php

namespace App\Filament\Resources\Sites\Tables;

use App\Models\Site;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class SitesTable
{
    /** $withServer is false inside a server sheet, where the column is redundant. */
    public static function configure(Table $table, bool $withServer = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->primaryPerName()->with(['server', 'dbRefs']))
            ->defaultSort('server_name')
            ->columns(array_values(array_filter([
                TextColumn::make('server_name')
                    ->label('Domaine')
                    ->description(fn (Site $record) => implode(', ', array_unique($record->aliases)))
                    ->searchable(query: fn (Builder $query, string $search) => $query->where(
                        fn (Builder $q) => $q->where('server_name', 'like', "%{$search}%")
                            ->orWhere('aliases', 'like', "%{$search}%")
                    ))
                    ->sortable()
                    ->placeholder('(sans ServerName)'),
                TextColumn::make('ports')->label('Ports')->state(fn (Site $record) => $record->ports()),
                IconColumn::make('https')->label('HTTPS')->boolean(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Site::TYPES[$state] ?? $state),
                $withServer ? TextColumn::make('server.name')->label('Serveur')->sortable()->searchable() : null,
                TextColumn::make('system_user')->label('Utilisateur système')->searchable()->placeholder('—'),
                TextColumn::make('php_version')->label('PHP')->placeholder('—')->toggleable(),
                TextColumn::make('dbRefs.db_name')->label('Bases')->listWithLineBreaks()->placeholder('—'),
                TextColumn::make('cert_expires_at')
                    ->label('Certificat')
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state) => $state && $state->lt(now()->addDays(30)) ? 'danger' : null)
                    ->placeholder('—'),
            ])))
            ->filters(array_values(array_filter([
                $withServer ? SelectFilter::make('server')->label('Serveur')->relationship('server', 'name') : null,
                SelectFilter::make('type')->label('Type')->options(Site::TYPES),
                SelectFilter::make('php_version')->label('PHP')->options(fn () => self::distinct('php_version')),
                SelectFilter::make('system_user')->label('Utilisateur système')->options(fn () => self::distinct('system_user')),
                TernaryFilter::make('https')->label('HTTPS'),
                TernaryFilter::make('partial')->label('Analyse partielle'),
            ])))
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function distinct(string $column): array
    {
        return Site::query()->whereNotNull($column)->distinct()->orderBy($column)->pluck($column, $column)->all();
    }
}
