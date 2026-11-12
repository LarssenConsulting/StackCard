<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\SiteDomain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fleet-wide list of host names: one entry per domain, with every vhost that serves it. Fields are
 * listed one by one, like ServerReadController; revoked servers are left out.
 */
class DomainReadController extends Controller
{
    private const SITE_FIELDS = [
        'port', 'https', 'cert_expires_at', 'type', 'document_root', 'app_dir', 'upstream', 'php_version', 'technologies',
    ];

    public function index(Request $request): JsonResponse
    {
        $filter = $request->query('server');
        if ($filter !== null && ! is_string($filter)) {
            return response()->json(['message' => 'Le paramètre « server » doit être un identifiant (slug) unique.'], 422);
        }

        $rows = SiteDomain::query()
            ->with(['site', 'server'])
            ->whereHas('server', function ($query) use ($filter) {
                $query->where('status', '!=', Server::STATUS_REVOKED);
                if ($filter !== null) {
                    $query->where('slug', $filter);
                }
            })
            ->get();

        $data = $rows->groupBy('domain')->sortKeys()->map(fn ($group, $domain) => [
            'domain' => $domain,
            'locations' => $group
                ->sortBy([
                    fn ($a, $b) => strcmp((string) $a->server->slug, (string) $b->server->slug),
                    fn ($a, $b) => $a->site->port <=> $b->site->port,
                    fn ($a, $b) => $a->id <=> $b->id,
                ])
                ->map(fn (SiteDomain $row) => [
                    'source' => 'apache',
                    'server' => ['slug' => $row->server->slug, 'name' => $row->server->name],
                    'server_stale' => $row->server->isStale(),
                    'role' => $row->role,
                    ...$row->site->only(self::SITE_FIELDS),
                ])->values()->all(),
        ])->values()->all();

        return response()->json(['data' => $data]);
    }
}
