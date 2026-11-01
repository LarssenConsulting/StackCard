<?php

namespace App\Livewire\Firewall;

use App\Models\ServerSection;
use App\Support\FirewallEngines;
use Filament\Tables\Columns\TextColumn;

class IptablesTable extends FirewallTable
{
    protected function heading(): string
    {
        return 'iptables';
    }

    protected function dataKey(): string
    {
        return 'iptables';
    }

    protected function engine(?ServerSection $network): array
    {
        return FirewallEngines::iptables($network, $this->dataKey());
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('family')->label('IP')->badge()->color('gray'),
            TextColumn::make('table')->label('Table')->placeholder('—'),
            TextColumn::make('chain')->label('Chaîne')->placeholder('—'),
            TextColumn::make('position')->label('#')->placeholder('—'),
            TextColumn::make('conditions')->label('Règle')->fontFamily('mono')->wrap()->searchable()->placeholder('—'),
            self::actionColumn(),
            TextColumn::make('comment')->label('Commentaire')->wrap()->placeholder('—'),
        ];
    }
}
