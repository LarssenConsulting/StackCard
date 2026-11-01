<?php

namespace App\Livewire\Firewall;

class IptablesLegacyTable extends IptablesTable
{
    protected function heading(): string
    {
        return 'iptables-legacy';
    }

    protected function dataKey(): string
    {
        return 'iptables_legacy';
    }
}
