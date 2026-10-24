<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Database extends Model
{
    protected $table = 'db_databases';

    protected $fillable = ['server_id', 'name', 'size_bytes'];

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function siteDbRefs(): HasMany
    {
        return $this->hasMany(SiteDbRef::class);
    }

    public function grants(): HasMany
    {
        return $this->hasMany(DbGrant::class);
    }

    /** Databases that no inventoried site is linked to. */
    public function scopeOrphans(Builder $query): Builder
    {
        return $query->whereDoesntHave('siteDbRefs');
    }

    /** Adds linked_sites_count: distinct site names, so a site served on ports 80 and 443 counts once. */
    public function scopeWithLinkedSitesCount(Builder $query): Builder
    {
        return $query->addSelect(['linked_sites_count' => SiteDbRef::query()
            ->join('sites', 'sites.id', '=', 'site_db_refs.site_id')
            ->whereColumn('site_db_refs.database_id', 'db_databases.id')
            ->selectRaw('count(distinct sites.server_name)'),
        ]);
    }

    /**
     * One reference per site and configuration file, with the ports of every vhost that reads it
     * in `ports` (the HTTP and HTTPS vhosts of a site usually point at the same .env).
     *
     * @return Collection<int, SiteDbRef>
     */
    public function linkedSiteRefs(): Collection
    {
        return $this->siteDbRefs()->with(['site.server', 'dbUser'])->get()
            ->sortBy(fn (SiteDbRef $ref) => sprintf('%s|%05d', $ref->site->server_name, $ref->site->port))
            ->groupBy(fn (SiteDbRef $ref) => implode('|', [
                $ref->site->server_id, $ref->site->server_name, $ref->db_user, $ref->resolution, $ref->source,
            ]))
            ->map(fn (Collection $refs) => $refs->first()->setAttribute(
                'ports',
                $refs->pluck('site.port')->unique()->implode(', '),
            ))
            ->values();
    }
}
