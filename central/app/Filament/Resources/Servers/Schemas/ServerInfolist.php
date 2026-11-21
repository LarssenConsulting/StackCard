<?php

namespace App\Filament\Resources\Servers\Schemas;

use App\Models\Server;
use App\Support\AgentRelease;
use App\Support\DisplaySettings;
use App\Support\FirewallStatus;
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
                        TextEntry::make('slug')->label('Slug')->copyable()->placeholder('—'),
                        TextEntry::make('slug_state')
                            ->label('État du slug')
                            ->badge()
                            ->color('warning')
                            ->state('non figé')
                            ->visible(fn (Server $record) => ! $record->isSlugLocked()),
                        TextEntry::make('connect_address')->label('Adresse de connexion')->placeholder('—')->copyable(),
                        TextEntry::make('connect_port')->label('Port SSH')->placeholder('—'),
                        TextEntry::make('ansible_state')
                            ->label('Inventaire Ansible')
                            ->badge()
                            ->color('gray')
                            ->state('exclu')
                            ->visible(fn (Server $record) => $record->ansible_excluded),
                        TextEntry::make('hostname')->label('Hostname')->placeholder('—'),
                        TextEntry::make('fqdn')->label('FQDN')->placeholder('—')->copyable(),
                        TextEntry::make('primary_ip')->label('IP principale')->placeholder('—')->copyable(),
                        TextEntry::make('approved_ip')
                            ->label('IP validée pour Ansible')
                            ->placeholder('—')
                            ->color(fn (Server $record) => $record->ipChangePending() ? 'warning' : null)
                            ->helperText(fn (Server $record) => $record->ipChangePending()
                                ? 'L’agent signale une autre IP : Ansible ignore ce serveur (sauf adresse de connexion manuelle) tant qu’un admin ne l’a pas validée.'
                                : null),
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
                        TextEntry::make('firewall_engines')
                            ->label('Pare-feu')
                            ->badge()
                            ->state(fn (Server $record) => FirewallStatus::badge($record->firewall_engines)['label'])
                            ->color(fn (Server $record) => FirewallStatus::badge($record->firewall_engines)['color'])
                            ->helperText(fn (Server $record) => self::firewallHelp($record)),
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
                        TextEntry::make('enrollment')
                            ->label('Jeton d’enrôlement')
                            ->state(fn (Server $record) => ($token = $record->pendingEnrollmentToken())
                                ? 'En attente, expire le '.DisplaySettings::formatDateTime($token->expires_at, ' à ')
                                : null)
                            ->placeholder(fn (Server $record) => $record->status === Server::STATUS_ACTIVE
                                ? 'Utilisé, agent enrôlé'
                                : 'Aucun jeton valide'),
                        TextEntry::make('machine_id')->label('machine-id')->placeholder('—'),
                    ]),
                TextEntry::make('notes')->label('Notes')->placeholder('—')->columnSpanFull(),
            ]);
    }

    /** UFW state and policies, and what the badge does not show. */
    private static function firewallHelp(Server $record): ?string
    {
        $parts = [];
        $parts[] = match ($record->ufw_status) {
            'active' => "UFW actif · entrant {$record->ufw_default_incoming}, sortant {$record->ufw_default_outgoing}, routé {$record->ufw_default_routed} · journalisation {$record->ufw_logging}",
            'inactive' => 'UFW inactif',
            null => null,
            default => "UFW : {$record->ufw_status}",
        };
        if (FirewallStatus::protects($record->firewall_engines) === null) {
            $parts[] = 'Moteurs non déterminés : il faut un inventaire avec la section réseau (agent 0.5 ou plus récent)';
        }
        $parts[] = 'Détail dans l’onglet « Pare-feu »';

        return implode(' · ', array_filter($parts));
    }
}
