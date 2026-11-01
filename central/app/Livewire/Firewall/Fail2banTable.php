<?php

namespace App\Livewire\Firewall;

use App\Models\ServerSection;
use App\Support\FirewallEngines;
use Filament\Tables\Columns\TextColumn;

/** The configuration of the jails: the banned addresses and counters are live data, not reported. */
class Fail2banTable extends FirewallTable
{
    protected function heading(): string
    {
        return 'Fail2Ban';
    }

    protected function engine(?ServerSection $network): array
    {
        return FirewallEngines::fail2ban($network);
    }

    protected function columns(): array
    {
        return [
            TextColumn::make('name')->label('Jail')->searchable()->placeholder('—'),
            TextColumn::make('backend')->label('Backend')->placeholder('—'),
            TextColumn::make('bantime')->label('Bannissement (s)')->placeholder('—'),
            TextColumn::make('findtime')->label('Fenêtre (s)')->placeholder('—'),
            TextColumn::make('maxretry')->label('Tentatives')->placeholder('—'),
            TextColumn::make('actions')->label('Actions')->wrap()->placeholder('—'),
            TextColumn::make('ignoreip')->label('IP ignorées')->wrap()->placeholder('—'),
            TextColumn::make('logpaths')->label('Logs surveillés')->wrap()->placeholder('—'),
        ];
    }
}
