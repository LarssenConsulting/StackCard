<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Filament\Resources\Servers\Actions\ApproveIpAction;
use App\Filament\Resources\Servers\Actions\GenerateEnrollmentTokenAction;
use App\Filament\Resources\Servers\Actions\UpgradeAgentAction;
use App\Filament\Resources\Servers\Schemas\ServerStatus;
use App\Models\Server;
use App\Support\AgentRelease;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Nom')
                    ->description(fn (Server $record) => $record->fqdn ?? $record->hostname)
                    ->searchable(['name', 'hostname', 'fqdn'])
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Slug')
                    ->searchable()
                    ->copyable()
                    ->description(fn (Server $record) => $record->isSlugLocked() ? null : 'non figé')
                    ->placeholder('—'),
                TextColumn::make('primary_ip')
                    ->label('Adresse')
                    ->state(fn (Server $record) => $record->effectiveAddress())
                    ->description(fn (Server $record) => match (true) {
                        filled($record->connect_address) => 'manuelle',
                        $record->ipChangePending() => "IP changée, à valider (validée : {$record->approved_ip})",
                        default => null,
                    })
                    ->color(fn (Server $record) => blank($record->connect_address) && $record->ipChangePending() ? 'warning' : null)
                    ->searchable(['primary_ip', 'connect_address'])
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('ansible')
                    ->label('Ansible')
                    ->badge()
                    ->state(fn (Server $record) => Server::ANSIBLE_STATES[$record->ansibleState()])
                    ->color(fn (Server $record) => match ($record->ansibleState()) {
                        'included' => 'success',
                        'ip_pending' => 'warning',
                        'no_address' => 'danger',
                        default => 'gray',
                    })
                    ->tooltip(fn (Server $record) => match ($record->ansibleState()) {
                        'included' => 'Dans l’inventaire Ansible, joint à '.$record->trustedAddress()
                            .($record->connect_port ? ':'.$record->connect_port : ''),
                        'excluded' => 'Exclu par un admin (fiche du serveur > Modifier)',
                        'ip_pending' => 'L’agent signale une nouvelle IP : à valider avant qu’Ansible s’y connecte',
                        'no_address' => 'Aucune adresse connue : saisir une adresse de connexion',
                        default => 'Serveur en attente ou révoqué',
                    }),
                TextColumn::make('os')->label('Système')->toggleable()->placeholder('—'),
                TextColumn::make('sites_count')->label('Sites')->counts('sites')->sortable(),
                TextColumn::make('databases_count')->label('Bases')->counts('databases')->sortable(),
                TextColumn::make('agent_version')
                    ->label('Agent')
                    ->formatStateUsing(fn (string $state) => AgentRelease::describe($state))
                    ->color(fn (?string $state) => AgentRelease::isOutdated($state) ? 'warning' : null)
                    ->tooltip(fn (?string $state) => AgentRelease::isOutdated($state) ? 'Voir comment mettre à jour' : null)
                    ->action(UpgradeAgentAction::make())
                    ->disabledClick(fn (?string $state) => ! AgentRelease::isOutdated($state))
                    ->placeholder('—'),
                TextColumn::make('last_seen_at')->label('Dernier envoi')->since()->sortable()->placeholder('jamais'),
                TextColumn::make('status')
                    ->label('État')
                    ->badge()
                    ->state(fn (Server $record) => ServerStatus::label($record))
                    ->color(fn (Server $record) => ServerStatus::color($record)),
            ])
            ->filters([
                SelectFilter::make('status')->label('État')->options([
                    Server::STATUS_ACTIVE => 'Actif',
                    Server::STATUS_PENDING => 'En attente',
                    Server::STATUS_REVOKED => 'Révoqué',
                ]),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                GenerateEnrollmentTokenAction::make()->label('Jeton d’enrôlement'),
                ApproveIpAction::make(),
            ]);
    }
}
