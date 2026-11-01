<?php

namespace App\Livewire\Firewall;

use App\Models\ServerSection;
use App\Support\FirewallEngines;
use Filament\Tables\Columns\TextColumn;

class NftablesTable extends FirewallTable
{
    protected function heading(): string
    {
        return 'nftables';
    }

    protected function engine(?ServerSection $network): array
    {
        return FirewallEngines::nftables($network);
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('table')->label('Table')->placeholder('—'),
            TextColumn::make('chain')->label('Chaîne')->placeholder('—'),
            TextColumn::make('conditions')->label('Règle')->fontFamily('mono')->wrap()->searchable()->placeholder('—'),
            self::actionColumn(),
            TextColumn::make('comment')->label('Commentaire')->wrap()->placeholder('—'),
        ];
    }
}
