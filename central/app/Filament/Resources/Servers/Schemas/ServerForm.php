<?php

namespace App\Filament\Resources\Servers\Schemas;

use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/** Only the name and notes are typed by hand: everything else comes from the agent. */
class ServerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nom')
                    ->helperText('Nom d’affichage dans Discover (le hostname réel est remonté par l’agent).')
                    ->required()
                    ->maxLength(255),
                Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]);
    }
}
