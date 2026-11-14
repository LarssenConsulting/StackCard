<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerSection;
use Illuminate\Http\JsonResponse;

/**
 * Read-only access to the inventory. Every field is listed on purpose: a column added later, or
 * the admin's notes, must not leave through this API by accident. Assessing a CVE is left to the
 * caller: `collected_at`, `error` and `source_errors` say how far the data can be trusted, and
 * a `null` means "not read", never "not exposed".
 */
class ServerReadController extends Controller
{
    private const SITE_FIELDS = [
        'server_name', 'aliases', 'port', 'https', 'cert_expires_at', 'type', 'document_root', 'app_dir', 'upstream',
        'php_version', 'system_user', 'system_group', 'owner_user', 'owner_group', 'process_manager', 'config_file',
        'partial', 'technologies',
    ];

    private const NODE_APP_FIELDS = ['name', 'version', 'node_version', 'listen', 'user', 'group', 'app_dir', 'process_manager', 'technologies'];

    private const CONTAINER_FIELDS = [
        'name', 'image', 'image_version', 'state', 'ports', 'compose_project', 'compose_service', 'compose_dir',
        'restart_policy', 'started_at',
    ];

    private const SECTION_STATE_FIELDS = ['collected_at', 'first_seen_at', 'last_seen_at', 'sha256', 'error', 'source_errors'];

    public function index(): JsonResponse
    {
        return response()->json(['data' => Server::query()->orderBy('id')->get()->map(self::summary(...))->all()]);
    }

    public function show(string $server): JsonResponse
    {
        $server = Server::findByKey($server);
        $sections = $this->latestSections($server, ['section', ...self::SECTION_STATE_FIELDS]);

        return response()->json(['data' => [
            ...self::summary($server),
            'ips' => $server->ips()->orderBy('id')->get()->map->only(['interface', 'address', 'prefix'])->all(),
            'sites' => $server->sites()->with('dbRefs')->orderBy('id')->get()->map(fn ($site) => [
                ...$site->only(self::SITE_FIELDS),
                'db_refs' => $site->dbRefs->map->only(['db_name', 'db_user', 'db_host', 'source'])->all(),
            ])->all(),
            'node_apps' => $server->nodeApps()->orderBy('id')->get()->map->only(self::NODE_APP_FIELDS)->all(),
            'docker_containers' => $server->dockerContainers()->orderBy('id')->get()->map->only(self::CONTAINER_FIELDS)->all(),
            'firewall' => [
                'status' => $server->ufw_status,
                'logging' => $server->ufw_logging,
                'default_incoming' => $server->ufw_default_incoming,
                'default_outgoing' => $server->ufw_default_outgoing,
                'default_routed' => $server->ufw_default_routed,
                'rules' => $server->firewallRules()->orderBy('position')->get()
                    ->map->only(['position', 'destination', 'action', 'source', 'ipv6', 'comment'])->all(),
            ],
            'databases' => $server->databases()->orderBy('name')->get()->map->only(['name', 'size_bytes'])->all(),
            'db_users' => $server->dbUsers()->with('grants.database')->orderBy('id')->get()->map(fn ($user) => [
                'user' => $user->user,
                'host' => $user->host,
                'grants' => $user->grants->map(fn ($grant) => [
                    'database' => $grant->database?->name,
                    'global' => $grant->global,
                    'privileges' => $grant->privileges,
                ])->all(),
            ])->all(),
            'sections' => $sections->mapWithKeys(fn (ServerSection $row) => [$row->section => $row->only(self::SECTION_STATE_FIELDS)])->all(),
            'sections_never_received' => array_values(array_diff(ServerSection::NAMES, $sections->pluck('section')->all())),
        ]]);
    }

    public function section(string $server, string $section): JsonResponse
    {
        $server = Server::findByKey($server);
        $row = $server->sections()->where('section', $section)->orderByDesc('id')->firstOrFail();

        return response()->json(['data' => [
            'server_id' => $server->id,
            'section' => $row->section,
            ...$row->only(self::SECTION_STATE_FIELDS),
            'data' => $row->data,
        ]]);
    }

    private static function summary(Server $server): array
    {
        $updatesState = $server->updatesState();

        return [
            ...$server->only([
                'id', 'name', 'slug', 'hostname', 'fqdn', 'status', 'primary_ip', 'os', 'kernel', 'virtualization',
                'apache_version', 'mysql_version', 'docker_version', 'agent_version', 'last_seen_at',
            ]),
            'slug_locked' => $server->isSlugLocked(),
            ...$server->only(['connect_address', 'connect_port', 'approved_ip', 'ansible_excluded']),
            'updates_state' => $updatesState,
            'updates_pending' => $updatesState === 'unknown' ? null : $server->updates_count,
            'security_updates_pending' => $updatesState === 'unknown' ? null : $server->security_updates_count,
            'reboot_required' => $server->reboot_required,
            'apt_cache_updated_at' => $server->apt_cache_updated_at,
            'stale' => $server->last_seen_at === null || $server->last_seen_at->lt(now()->subHours(Server::STALE_AFTER_HOURS)),
        ];
    }

    /** The newest row of each section, without the heavy `data` column. */
    private function latestSections(Server $server, array $columns)
    {
        $ids = $server->sections()->selectRaw('MAX(id) as id')->groupBy('section')->pluck('id')->all();

        return $server->sections()->select($columns)->whereIn('id', $ids)->orderBy('section')->get();
    }
}
