<?php

namespace App\Filament\Resources\Databases\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

class DatabasesTable
{
    /** $withServer is false inside a server sheet, where the column is redundant. */
    public static function configure(Table $table, bool $withServer = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withLinkedSitesCount())
            ->defaultSort('name')
            ->columns(array_values(array_filter([
                TextColumn::make('name')->label('Base')->searchable()->sortable(),
                $withServer ? TextColumn::make('server.name')->label('Serveur')->searchable()->sortable() : null,
                TextColumn::make('size_bytes')
                    ->label('Taille')
                    ->formatStateUsing(fn (int $state) => Number::fileSize($state, precision: 1))
                    ->sortable(),
                TextColumn::make('linked_sites_count')
                    ->label('Sites liés')
                    ->tooltip('Sites dont le wp-config.php ou le .env désigne cette base')
                    ->badge()
                    ->color(fn (int $state) => $state === 0 ? 'warning' : 'success'),
            ])))
            ->filters(array_values(array_filter([
                $withServer ? SelectFilter::make('server')->label('Serveur')->relationship('server', 'name') : null,
                Filter::make('orphans')
                    ->label('Orphelines (aucun site)')
                    ->query(fn (Builder $query) => $query->orphans()),
            ])))
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
