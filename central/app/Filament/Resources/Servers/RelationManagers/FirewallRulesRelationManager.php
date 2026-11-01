<?php

namespace App\Filament\Resources\Servers\RelationManagers;

use App\Models\FirewallRule;
use App\Livewire\Firewall\IptablesLegacyTable;
use App\Livewire\Firewall\IptablesTable;
use App\Livewire\Firewall\NftablesTable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;

class FirewallRulesRelationManager extends RelationManager
{
    protected static string $relationship = 'firewallRules';

    protected static ?string $title = 'Pare-feu';

    public function isReadOnly(): bool
    {
        return true;
    }

    /** One table per firewall: UFW here, the others read from the latest `network` section. */
    public function content(Schema $schema): Schema
    {
        $server = ['serverId' => $this->getOwnerRecord()->getKey()];

        return $schema->components([
            $this->getTabsContentComponent(),
            RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_BEFORE),
            EmbeddedTable::make(),
            Livewire::make(NftablesTable::class, $server),
            Livewire::make(IptablesTable::class, $server),
            Livewire::make(IptablesLegacyTable::class, $server),
            View::make('filament.server-firewall.note'),
            RenderHook::make(PanelsRenderHook::RESOURCE_RELATION_MANAGER_AFTER),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('UFW')
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
