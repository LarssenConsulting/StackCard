<?php

namespace App\Filament\Resources\Servers\Tables;

use App\Filament\Resources\Servers\Actions\ApproveIpAction;
use App\Filament\Resources\Servers\Actions\GenerateEnrollmentTokenAction;
use App\Filament\Resources\Servers\Actions\UpgradeAgentAction;
use App\Filament\Resources\Servers\Schemas\ServerStatus;
use App\Models\Server;
use App\Support\AgentRelease;
use App\Support\DisplaySettings;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('updates')
                    ->label('Mises à jour')
                    ->badge()
                    ->state(fn (Server $record) => self::updatesLabel($record))
                    ->description(fn (Server $record) => $record->reboot_required ? 'redémarrage requis' : null)
                    ->color(fn (Server $record) => self::updatesColor($record))
                    ->tooltip(fn (Server $record) => self::updatesTooltip($record)),
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
                Filter::make('security_updates')
                    ->label('Mises à jour de sécurité')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('security_updates_count', '>', 0)),
                Filter::make('reboot_required')
                    ->label('Redémarrage requis')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('reboot_required', true)),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
                GenerateEnrollmentTokenAction::make()->label('Jeton d’enrôlement'),
                ApproveIpAction::make(),
            ]);
    }

    /** « inconnu », « à jour », « N » or « N dont M sécu »; a stale figure carries the cache date. */
    public static function updatesLabel(Server $server): string
    {
        $state = $server->updatesState();
        if ($state === 'unknown') {
            return 'inconnu';
        }
        $total = (int) $server->updates_count;
        $security = (int) $server->security_updates_count;
        $text = match (true) {
            $total === 0 => 'à jour',
            $security > 0 => "{$total} dont {$security} sécu",
            default => (string) $total,
        };
        if ($state === 'stale' && $total === 0 && filled($server->updates_error)) {
            return 'inconnu (erreur)';
        }
        if ($state === 'stale') {
            $text .= $server->apt_cache_updated_at
                ? ' (au '.$server->apt_cache_updated_at->copy()->timezone(DisplaySettings::timezone())->format('d/m').')'
                : ' (date inconnue)';
        }

        return $text;
    }

    public static function updatesColor(Server $server): string
    {
        $state = $server->updatesState();
        $total = (int) $server->updates_count;

        return match (true) {
            $state === 'unknown' => 'gray',
            $total === 0 => $state === 'ok' ? 'success' : 'gray',
            (int) $server->security_updates_count > 0 => 'danger',
            default => 'warning',
        };
    }

    public static function updatesTooltip(Server $server): string
    {
        $parts = [];
        $parts[] = match (true) {
            $server->updatesState() === 'unknown' => 'Aucun relevé valide reçu',
            $server->apt_cache_updated_at === null => 'Cache APT : date inconnue',
            default => 'Cache APT mis à jour '.$server->apt_cache_updated_at->diffForHumans(),
        };
        if (filled($server->updates_error)) {
            $parts[] = 'Erreur : '.$server->updates_error;
        }
        if ($server->updatesState() === 'stale') {
            $parts[] = 'Chiffres peut-être périmés';
        }
        if ($server->reboot_required) {
            $parts[] = 'Redémarrage requis';
        }

        return implode(' · ', $parts);
    }
}
