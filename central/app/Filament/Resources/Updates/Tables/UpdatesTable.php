<?php

namespace App\Filament\Resources\Updates\Tables;

use App\Filament\Resources\Servers\ServerResource;
use App\Models\Server;
use App\Models\ServerUpdate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** One row per (package, candidate version); the columns summarise every non-revoked server waiting for it. */
class UpdatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('members.server'))
            ->defaultSort('package')
            ->emptyStateHeading('Aucune mise à jour en attente connue')
            ->columns([
                TextColumn::make('package')->label('Paquet')->searchable()->sortable(),
                TextColumn::make('versions')
                    ->label('Installée(s) → Disponible')
                    ->state(fn (ServerUpdate $record) => self::group($record)->pluck('installed_version')
                        ->map(fn (?string $v) => $v ?? '—')->unique()->sort()->implode(', ').' → '.$record->candidate_version),
                TextColumn::make('security')
                    ->label('Sécurité')
                    ->badge()
                    ->state(fn (ServerUpdate $record) => self::group($record)->contains('security', true) ? 'sécurité' : 'normale')
                    ->color(fn (string $state) => $state === 'sécurité' ? 'danger' : 'gray'),
                TextColumn::make('servers')
                    ->label('Serveurs')
                    ->state(function (ServerUpdate $record) {
                        $servers = self::servers($record);

                        return $servers->count().' : '.$servers->map(fn (Server $server) => '<a class="underline" href="'
                            .e(ServerResource::getUrl('view', ['record' => $server])).'">'.e($server->slug ?? $server->name).'</a>')
                            ->implode(', ');
                    })
                    ->html(),
            ])
            ->filters([
                TernaryFilter::make('security')
                    ->label('Sécurité')
                    ->trueLabel('Sécurité seulement')
                    ->falseLabel('Hors sécurité')
                    ->queries(
                        true: fn (Builder $query) => self::groupHasSecurity($query, true),
                        false: fn (Builder $query) => self::groupHasSecurity($query, false),
                    ),
            ]);
    }

    /** @return Collection<int, ServerUpdate> the rows of the record's group on non-revoked servers */
    private static function group(ServerUpdate $record): Collection
    {
        return $record->members
            ->filter(fn (ServerUpdate $m) => $m->candidate_version === $record->candidate_version
                && $m->server && $m->server->status !== Server::STATUS_REVOKED)
            ->values();
    }

    /** @return Collection<int, Server> */
    private static function servers(ServerUpdate $record): Collection
    {
        return self::group($record)->pluck('server')->unique('id')
            ->sortBy(fn (Server $server) => $server->slug ?? $server->name)->values();
    }

    /** Keep the groups where some row (true) or no row (false) is a security update. */
    private static function groupHasSecurity(Builder $query, bool $security): Builder
    {
        $exists = fn ($sub) => $sub->selectRaw('1')->from('server_updates as su')
            ->join('servers as s', 's.id', '=', 'su.server_id')
            ->where('s.status', '!=', Server::STATUS_REVOKED)
            ->whereColumn('su.package', 'server_updates.package')
            ->whereColumn('su.candidate_version', 'server_updates.candidate_version')
            ->where('su.security', true);

        return $security ? $query->whereExists($exists) : $query->whereNotExists($exists);
    }
}
