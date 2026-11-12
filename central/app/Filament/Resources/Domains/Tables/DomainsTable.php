<?php

namespace App\Filament\Resources\Domains\Tables;

use App\Filament\Resources\Servers\ServerResource;
use App\Filament\Resources\Sites\SiteResource;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteDomain;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** One row per domain; the columns summarise every location (server + vhost) serving it. */
class DomainsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['site', 'server', 'locations.site', 'locations.server']))
            ->defaultSort('domain')
            ->columns([
                TextColumn::make('domain')->label('Domaine')->searchable()->sortable(),
                TextColumn::make('role')
                    ->label('Rôle')
                    ->badge()
                    ->state(fn (SiteDomain $record) => $record->locations->contains('role', SiteDomain::ROLE_SERVER_NAME)
                        ? SiteDomain::ROLE_SERVER_NAME
                        : SiteDomain::ROLE_ALIAS)
                    ->formatStateUsing(fn (string $state) => $state === SiteDomain::ROLE_SERVER_NAME ? 'principal' : 'alias')
                    ->color(fn (string $state) => $state === SiteDomain::ROLE_SERVER_NAME ? 'primary' : 'gray'),
                TextColumn::make('servers')
                    ->label('Serveur(s)')
                    ->state(fn (SiteDomain $record) => $record->locations
                        ->pluck('server.slug')->filter()->unique()->sort()->values()->all())
                    ->badge()
                    ->color('gray')
                    ->url(function (SiteDomain $record) {
                        $servers = $record->locations->pluck('server')->filter()->unique('id');

                        return $servers->count() === 1
                            ? ServerResource::getUrl('view', ['record' => $servers->first()])
                            : null;
                    })
                    ->placeholder('—'),
                TextColumn::make('ports')
                    ->label('Ports')
                    ->state(fn (SiteDomain $record) => self::sites($record)->pluck('port')->unique()->sort()->implode(', ')),
                IconColumn::make('https')
                    ->label('HTTPS')
                    ->boolean()
                    ->state(fn (SiteDomain $record) => self::sites($record)->contains('https', true)),
                TextColumn::make('types')
                    ->label('Type(s)')
                    ->state(fn (SiteDomain $record) => self::sites($record)->pluck('type')->unique()->sort()
                        ->map(fn (?string $type) => Site::TYPES[$type] ?? $type)->values()->all())
                    ->badge(),
                TextColumn::make('technologies')
                    ->label('Technos')
                    ->state(fn (SiteDomain $record) => collect(Site::technologyLabels(
                        self::sites($record)->flatMap(fn (Site $site) => $site->technologies ?? [])->all()
                    ))->unique()->values()->all())
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('cert')
                    ->label('Certificat')
                    ->state(fn (SiteDomain $record) => self::sites($record)->pluck('cert_expires_at')->filter()->min())
                    ->date()
                    ->color(fn ($state) => $state && $state->lt(now()->addDays(30)) ? 'danger' : null)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('server_id')
                    ->label('Serveur')
                    ->options(fn () => Server::query()
                        ->where('status', '!=', Server::STATUS_REVOKED)->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? self::domainHas($query, fn (Builder $location) => $location->where('server_id', $data['value']))
                        : $query),
                SelectFilter::make('type')
                    ->label('Type')
                    ->options(Site::TYPES)
                    ->query(fn (Builder $query, array $data) => filled($data['value'])
                        ? self::domainHas($query, fn (Builder $location) => $location
                            ->whereHas('site', fn (Builder $site) => $site->where('type', $data['value'])))
                        : $query),
                TernaryFilter::make('https')
                    ->label('HTTPS')
                    ->queries(
                        true: fn (Builder $query) => self::domainHas($query, fn (Builder $location) => $location
                            ->whereHas('site', fn (Builder $site) => $site->where('https', true))),
                        false: fn (Builder $query) => $query->whereNotIn('domain', fn ($sub) => $sub->select('domain')
                            ->from('site_domains')
                            ->whereIn('site_id', fn ($s) => $s->select('id')->from('sites')->where('https', true))),
                    ),
            ])
            ->recordUrl(fn (SiteDomain $record) => $record->site
                ? SiteResource::getUrl('view', ['record' => $record->site])
                : null);
    }

    /** @return Collection<int, Site> */
    private static function sites(SiteDomain $record): Collection
    {
        return $record->locations->pluck('site')->filter();
    }

    /** Keep the domains having at least one location matching the constraint. */
    private static function domainHas(Builder $query, \Closure $constraint): Builder
    {
        return $query->whereIn('domain', fn ($sub) => $sub->select('domain')->from('site_domains')
            ->whereIn('id', SiteDomain::query()->select('id')->tap($constraint)->toBase()));
    }
}
