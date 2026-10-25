<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use App\Support\AgentRelease;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ServerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make('Identité')
                    ->schema([
                        TextEntry::make('name')->label('Nom'),
                        TextEntry::make('hostname')->label('Hostname')->placeholder('—'),
                        TextEntry::make('fqdn')->label('FQDN')->placeholder('—')->copyable(),
                        TextEntry::make('primary_ip')->label('IP principale')->placeholder('—')->copyable(),
                        TextEntry::make('ips.address')->label('Toutes les IP')->listWithLineBreaks()->placeholder('—'),
                    ]),
                Section::make('Système')
                    ->schema([
                        TextEntry::make('os')->label('Système')->placeholder('—'),
                        TextEntry::make('kernel')->label('Noyau')->placeholder('—'),
                        TextEntry::make('virtualization')->label('Virtualisation')->placeholder('—'),
                        TextEntry::make('apache_version')->label('Apache')->placeholder('—'),
                        TextEntry::make('mysql_version')->label('MySQL / MariaDB')->placeholder('—'),
                        TextEntry::make('docker_version')->label('Docker')->placeholder('—'),
                    ]),
                Section::make('Agent')
                    ->schema([
                        TextEntry::make('status')
                            ->label('État')
                            ->badge()
                            ->state(fn (Server $record) => ServerStatus::label($record))
                            ->color(fn (Server $record) => ServerStatus::color($record)),
                        TextEntry::make('last_seen_at')->label('Dernier envoi')->since()->placeholder('jamais'),
                        TextEntry::make('last_source_ip')->label('IP source du dernier envoi')->placeholder('—'),
                        TextEntry::make('agent_version')
                            ->label('Version de l’agent')
                            ->formatStateUsing(fn (string $state) => AgentRelease::describe($state))
                            ->color(fn (?string $state) => AgentRelease::isOutdated($state) ? 'warning' : null)
                            ->placeholder('—'),
                        TextEntry::make('machine_id')->label('machine-id')->placeholder('—'),
                    ]),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]);
    }
}
