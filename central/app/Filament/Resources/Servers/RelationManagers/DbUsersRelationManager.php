<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\DbUser;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DbUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'dbUsers';

    protected static ?string $title = 'Comptes MySQL';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('grants.database'))
            ->defaultSort('user')
            ->columns([
                TextColumn::make('user')->label('Compte')->searchable()->sortable(),
                TextColumn::make('host')->label('Hôte')->searchable(),
                TextColumn::make('grants')
                    ->label('Droits')
                    ->state(fn (DbUser $record) => $record->grants->map(
                        fn ($grant) => ($grant->global ? 'GLOBAL' : $grant->database?->name).' : '.implode(', ', $grant->privileges)
                    )->all())
                    ->listWithLineBreaks()
                    ->placeholder('aucun'),
            ]);
    }
}
