<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSection;
use App\Models\ServerUpdate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pending APT updates and installed package versions, for external tools (CVE assessment).
 * Fields are listed one by one, like ServerReadController; revoked servers are left out of the
 * fleet-wide answers. A `null` means "not read", never "not exposed".
 */
class UpdateReadController extends Controller
{
    private const UPDATE_FIELDS = ['package', 'arch', 'installed_version', 'candidate_version', 'security', 'origins'];

    public function server(string $server): JsonResponse
    {
        $server = Server::findByKey($server);

        $kernel = $server->latestValidSection('kernel');
        $apt = $server->latestValidSection('apt_updates');

        return response()->json(['data' => [
            'slug' => $server->slug,
            'updates_state' => $server->updatesState(),
            'apt_cache_updated_at' => $server->apt_cache_updated_at,
            'updates_received_at' => $server->updates_received_at,
            'updates_error' => $server->updates_error,
            'reboot_required' => $server->reboot_required,
            'reboot_required_packages' => $kernel?->data['reboot_required_pkgs'] ?? null,
            'held' => $apt?->data['held'] ?? null,
            'collected_at' => $apt?->collected_at,
            'source_errors' => $apt?->source_errors,
            'updates' => $server->updates()->orderBy('package')->orderBy('arch')->get()
                ->map->only(self::UPDATE_FIELDS)->all(),
        ]]);
    }

    public function fleet(Request $request): JsonResponse
    {
        $package = $request->query('package');
        if ($package !== null && ! is_string($package)) {
            return $this->invalid('Le paramètre « package » doit être un nom de paquet unique.');
        }
        $security = $request->query('security');
        if ($security !== null && ! in_array($security, ['0', '1', 'true', 'false'], true)) {
            return $this->invalid('Le paramètre « security » doit valoir 1 ou 0.');
        }

        $rows = ServerUpdate::query()
            ->with('server')
            ->whereHas('server', fn ($query) => $query->where('status', '!=', Server::STATUS_REVOKED))
            ->when($package !== null, fn ($query) => $query->where('package', $package))
            ->when(in_array($security, ['1', 'true'], true), fn ($query) => $query->where('security', true))
            ->get();

        $data = $rows->groupBy(fn (ServerUpdate $row) => $row->package."\0".$row->candidate_version)
            ->map(function ($group) {
                $first = $group->first();

                return [
                    'package' => $first->package,
                    'candidate_version' => $first->candidate_version,
                    'security' => $group->contains(fn (ServerUpdate $row) => $row->security),
                    'installed_versions' => $group->pluck('installed_version')->unique()->sort()->values()->all(),
                    'servers' => $group->sortBy([
                        fn ($a, $b) => strcmp((string) $a->server->slug, (string) $b->server->slug),
                        fn ($a, $b) => $a->id <=> $b->id,
                    ])->map(fn (ServerUpdate $row) => [
                        'slug' => $row->server->slug,
                        'name' => $row->server->name,
                        'installed_version' => $row->installed_version,
                        'updates_state' => $row->server->updatesState(),
                    ])->values()->all(),
                ];
            })
            ->sortBy([
                fn ($a, $b) => strcmp($a['package'], $b['package']),
                fn ($a, $b) => strcmp((string) $a['candidate_version'], (string) $b['candidate_version']),
            ])->values()->all();

        return response()->json(['data' => $data]);
    }

    public function package(Request $request): JsonResponse
    {
        $name = $request->query('name');
        if (! is_string($name) || ! preg_match('/^[a-z0-9][a-z0-9.+-]+\z/D', $name)) {
            return $this->invalid('Le paramètre « name » est obligatoire : un nom de paquet Debian unique (minuscules, chiffres, « . », « + » et « - »).');
        }

        $servers = Server::query()->where('status', '!=', Server::STATUS_REVOKED)->orderBy('id')->get();

        // Pending updates first: one query for the whole fleet, first architecture per server.
        $updates = ServerUpdate::query()
            ->whereIn('server_id', $servers->pluck('id'))
            ->where('package', $name)
            ->orderBy('arch')->orderBy('id')
            ->get()->groupBy('server_id')->map->first();

        // Latest valid `packages` section per server (one id query); `data` is loaded one row at a time.
        $latestIds = ServerSection::query()
            ->where('section', 'packages')->whereNull('error')->whereNotNull('data')
            ->whereIn('server_id', $servers->pluck('id'))
            ->selectRaw('server_id, MAX(id) as id')->groupBy('server_id')->pluck('id', 'server_id');

        $data = [];
        foreach ($servers as $server) {
            $update = $updates->get($server->id);
            $collectedAt = null;
            $installed = $update?->installed_version;

            if ($latestIds->has($server->id)) {
                $row = ServerSection::query()->find($latestIds[$server->id], $update === null ? ['id', 'collected_at', 'data'] : ['id', 'collected_at']);
                $collectedAt = $row->collected_at;
                if ($update === null) {
                    $installed = collect($row->data)->where('package', $name)->sortBy('arch')->first()['version'] ?? null;
                }
                unset($row);
            }
            if ($update === null && $installed === null) {
                continue;
            }

            $data[] = [
                'slug' => $server->slug,
                'name' => $server->name,
                'os' => $server->os,
                'installed_version' => $installed,
                'candidate_version' => $update?->candidate_version,
                'security' => $update?->security,
                'updates_state' => $server->updatesState(),
                'apt_cache_updated_at' => $server->apt_cache_updated_at,
                'packages_collected_at' => $collectedAt,
            ];
        }

        return response()->json(['data' => $data]);
    }

    private function invalid(string $message): JsonResponse
    {
        return response()->json(['message' => $message], 422);
    }
}
