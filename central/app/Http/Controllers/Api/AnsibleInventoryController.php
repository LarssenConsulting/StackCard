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
            ->where('ansible_excluded', false)
            ->orderBy('slug')
            ->get()
            ->filter(fn (Server $server) => $server->ansibleState() === 'included');

        $hostvars = [];
        $groups = [];

        foreach ($servers as $server) {
            $slug = (string) $server->slug;
            $stale = $server->isStale();
            $updatesState = $server->updatesState();
            $known = $updatesState !== 'unknown';

            $vars = ['ansible_host' => $server->trustedAddress()];
            if ($server->connect_port) {
                $vars['ansible_port'] = (int) $server->connect_port;
            }
            $hostvars[$slug] = $vars + [
                'stackcard_id' => $server->id,
                'stackcard_name' => self::inert($server->name),
                'stackcard_hostname' => self::inert($server->hostname),
                'stackcard_fqdn' => self::inert($server->fqdn),
                'stackcard_os' => self::inert($server->os),
                'stackcard_stale' => $stale,
                'stackcard_last_seen_at' => $server->last_seen_at,
                'stackcard_updates_state' => $updatesState,
                'stackcard_updates_pending' => $known ? $server->updates_count : null,
                'stackcard_security_updates_pending' => $known ? $server->security_updates_count : null,
                'stackcard_reboot_required' => $server->reboot_required,
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
            // Last known data whatever the freshness (better one server too many than one forgotten);
            // a server that never reported its updates (unknown) is in neither of these two groups.
            if ($known) {
                if ($server->updates_count > 0) {
                    $groups['updates_pending'][] = $slug;
                }
                if ($server->security_updates_count > 0) {
                    $groups['security_updates'][] = $slug;
                }
            }
            // Reboot comes from the kernel section (older agents send it too), not from the updates state.
            if ($server->reboot_required) {
                $groups['reboot_required'][] = $slug;
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

    /**
     * Ansible templates variable values when they are used: a hostname like `{{ lookup('pipe', …) }}`
     * reported by a compromised agent would run on the Semaphore controller. Free text that could open
     * a Jinja block is not sent at all.
     */
    private static function inert(?string $value): ?string
    {
        return $value !== null && preg_match('/\{[{%#]/', $value) ? null : $value;
    }
}
