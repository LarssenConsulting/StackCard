<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Filament\Resources\Servers\Schemas\ServerStatus;
use App\Models\Server;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->description(fn (Server $record) => $record->fqdn ?? $record->hostname)
                    ->searchable(['name', 'hostname', 'fqdn'])
                    ->sortable(),
                TextColumn::make('primary_ip')->label('IP')->searchable()->copyable()->placeholder('—'),
                TextColumn::make('os')->label('Système')->toggleable()->placeholder('—'),
                TextColumn::make('sites_count')->label('Sites')->counts('sites')->sortable(),
                TextColumn::make('databases_count')->label('Bases')->counts('databases')->sortable(),
                TextColumn::make('last_seen_at')->label('Dernier envoi')->since()->sortable()->placeholder('jamais'),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->state(fn (Server $record) => ServerStatus::label($record))
                    ->color(fn (Server $record) => ServerStatus::color($record)),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')->options([
                    Server::STATUS_ACTIVE => 'Actif',
                    Server::STATUS_PENDING => 'En attente',
                    Server::STATUS_REVOKED => 'Révoqué',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
