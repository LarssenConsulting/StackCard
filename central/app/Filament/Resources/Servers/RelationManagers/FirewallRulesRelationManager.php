<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\FirewallRule;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FirewallRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'firewallRules';

    protected static ?string $title = 'Pare-feu (UFW)';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->emptyStateHeading(fn () => match ($this->getOwnerRecord()->ufw_status) {
                'active' => 'Aucune règle UFW',
                'inactive' => 'UFW est inactif sur ce serveur',
                default => 'UFW non installé ou non remonté par l’agent',
            })
            ->columns([
                TextColumn::make('position')->label('#')->sortable(),
                TextColumn::make('destination')
                    ->label('Destination')
                    ->searchable(['destination', 'source', 'comment']),
                TextColumn::make('action')
                    ->label('Action')
                    ->badge()
                    ->color(fn (string $state) => match (strtok($state, ' ')) {
                        'ALLOW' => 'success',
                        'LIMIT' => 'warning',
                        default => 'danger',
                    }),
                TextColumn::make('source')->label('Source'),
                TextColumn::make('ipv6')
                    ->label('IP')
                    ->state(fn (FirewallRule $record) => $record->ipv6 ? 'IPv6' : 'IPv4')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('comment')->label('Commentaire')->placeholder('—'),
            ]);
    }
}
