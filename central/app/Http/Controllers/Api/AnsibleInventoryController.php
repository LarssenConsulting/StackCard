<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Server;
use Illuminate\Http\JsonResponse;

/**
 * Ansible dynamic inventory (`--list` format with `_meta.hostvars`). Hosts are named by their slug;
 * only active servers with a reachable address are listed. Fields are listed one by one.
 */
class AnsibleInventoryController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $servers = Server::query()
            ->where('status', Server::STATUS_ACTIVE)
            ->orderBy('slug')
            ->get()
            ->filter(fn (Server $server) => $server->effectiveAddress() !== null);

        $hostvars = [];
        $groups = [];

        foreach ($servers as $server) {
            $slug = (string) $server->slug;
            $stale = $server->isStale();

            $vars = ['ansible_host' => $server->effectiveAddress()];
            if ($server->connect_port) {
                $vars['ansible_port'] = (int) $server->connect_port;
            }
            $hostvars[$slug] = $vars + [
                'stackcard_id' => $server->id,
                'stackcard_name' => $server->name,
                'stackcard_hostname' => $server->hostname,
                'stackcard_fqdn' => $server->fqdn,
                'stackcard_os' => $server->os,
                'stackcard_stale' => $stale,
                'stackcard_last_seen_at' => $server->last_seen_at,
            ];

            if (preg_match('/Ubuntu (\d{2})\.(\d{2})/', (string) $server->os, $m)) {
                $groups["ubuntu_{$m[1]}_{$m[2]}"][] = $slug;
            }
            foreach (['apache' => $server->apache_version, 'mysql' => $server->mysql_version, 'docker' => $server->docker_version] as $group => $version) {
                if ($version !== null && $version !== '') {
                    $groups[$group][] = $slug;
                }
            }
            if ($stale) {
                $groups['stale'][] = $slug;
            }
        }

        ksort($groups);
        $hosts = array_keys($hostvars);
        sort($hosts);

        $inventory = [
            '_meta' => ['hostvars' => (object) $hostvars],
            'all' => ['hosts' => $hosts, 'children' => array_keys($groups)],
        ];
        foreach ($groups as $name => $members) {
            sort($members);
            $inventory[$name] = ['hosts' => $members];
        }

        return response()->json($inventory);
    }
}
