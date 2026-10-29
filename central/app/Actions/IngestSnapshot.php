<?php

namespace App\Actions;

use App\Models\DockerContainer;
use App\Models\NodeApp;
use App\Models\Server;
use App\Models\Snapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Store a validated snapshot: keep it as history, then replace the server's
 * current state (IPs, sites, databases, accounts, grants, firewall) in one transaction.
 */
class IngestSnapshot
{
    public function __construct(private LinkDatabases $linkDatabases) {}

    public function __invoke(Server $server, array $snapshot, ?string $sourceIp): Snapshot
    {
        return DB::transaction(function () use ($server, $snapshot, $sourceIp) {
            $record = $this->store($server, $snapshot, $sourceIp);
            ($this->linkDatabases)();

            return $record;
        });
    }

    private function store(Server $server, array $snapshot, ?string $sourceIp): Snapshot
    {
        $record = $server->snapshots()->create([
            'received_at' => now(),
            'source_ip' => $sourceIp,
            'schema_version' => $snapshot['schema_version'],
            'payload' => $snapshot,
            'error_count' => count($snapshot['errors']),
            'warnings' => $this->warnings($server, $snapshot),
        ]);

        $info = $snapshot['server'];
        $server->update([
            'hostname' => $info['hostname'],
            'fqdn' => $info['fqdn'] ?? null,
            'primary_ip' => $info['primary_ip'] ?? null,
            'os' => $info['os'] ?? null,
            'kernel' => $info['kernel'] ?? null,
            'virtualization' => $info['virtualization'] ?? null,
            'apache_version' => $snapshot['apache']['version'] ?? null,
            'mysql_version' => $snapshot['mysql']['version'] ?? null,
            'docker_version' => $snapshot['docker']['version'] ?? null,
            'ufw_status' => $snapshot['ufw']['status'] ?? null,
            'ufw_logging' => $snapshot['ufw']['logging'] ?? null,
            'ufw_default_incoming' => $snapshot['ufw']['default_incoming'] ?? null,
            'ufw_default_outgoing' => $snapshot['ufw']['default_outgoing'] ?? null,
            'ufw_default_routed' => $snapshot['ufw']['default_routed'] ?? null,
            'agent_version' => $snapshot['agent_version'] ?? null,
            'last_seen_at' => now(),
            'last_source_ip' => $sourceIp,
        ]);

        $this->clearState($server);
        $this->storeIps($server, $info['interfaces']);
        $nodeApps = $this->storeNodeApps($server, $snapshot['node']['apps'] ?? []);
        $containers = $this->storeDockerContainers($server, $snapshot['docker']['containers'] ?? []);
        $this->storeSites($server, $snapshot['apache']['sites'] ?? [], $nodeApps, $containers);
        $this->storeFirewallRules($server, $snapshot['ufw']['rules'] ?? []);
        $this->storeMysql($server, $snapshot['mysql'] ?? null);

        return $record;
    }

    private function warnings(Server $server, array $snapshot): array
    {
        $received = $snapshot['server']['machine_id'] ?? null;
        if ($server->machine_id && $received && $received !== $server->machine_id) {
            return ["machine_id reçu ({$received}) différent de celui enregistré à l’enrôlement ({$server->machine_id})."];
        }

        return [];
    }

    private function clearState(Server $server): void
    {
        $server->ips()->delete();
        $server->sites()->delete();      // cascades to site_db_refs
        $server->nodeApps()->delete();
        $server->dockerContainers()->delete();
        $server->firewallRules()->delete();
        $server->dbUsers()->delete();    // cascades to db_grants
        $server->databases()->delete();
    }

    private function storeIps(Server $server, array $interfaces): void
    {
        foreach ($interfaces as $interface) {
            foreach ($interface['addresses'] as $cidr) {
                [$address, $prefix] = array_pad(explode('/', $cidr, 2), 2, null);
                $server->ips()->create([
                    'interface' => $interface['name'],
                    'address' => $address,
                    'prefix' => is_numeric($prefix) ? (int) $prefix : null,
                ]);
            }
        }
    }

    /**
     * Node sites proxy to a loopback address (the agent only types them `node` then), so only an
     * app listening on loopback or on every address can serve them.
     *
     * @return array<int, int> node app id by port, for ports exactly one such app listens on
     */
    private function storeNodeApps(Server $server, array $apps): array
    {
        $candidates = [];
        foreach ($apps as $data) {
            $app = $server->nodeApps()->create($data);
            foreach ($app->listen as $socket) {
                if (in_array($socket['address'], [...NodeApp::LOOPBACK, ...NodeApp::WILDCARD], true)) {
                    $candidates[$socket['port']][$app->id] = $app->id;
                }
            }
        }

        // Two apps on the same port (e.g. 127.0.0.1 and ::1): which one Apache reaches is unknown.
        return array_map(fn (array $ids) => array_key_first($ids), array_filter($candidates, fn (array $ids) => count($ids) === 1));
    }

    /**
     * Docker sites proxy to a loopback address too: only a container publishing the port on
     * loopback or on every address can serve them.
     *
     * @return array<int, int> container id by host port, for ports exactly one such container publishes
     */
    private function storeDockerContainers(Server $server, array $containers): array
    {
        $candidates = [];
        foreach ($containers as $data) {
            $container = $server->dockerContainers()->create(array_merge(Arr::except($data, ['id', 'started_at']), [
                'container_id' => $data['id'],
                'started_at' => isset($data['started_at']) ? Carbon::parse($data['started_at']) : null,
            ]));
            foreach ($container->ports as $port) {
                if (in_array($port['address'], [...NodeApp::LOOPBACK, ...NodeApp::WILDCARD], true)) {
                    $candidates[$port['port']][$container->id] = $container->id;
                }
            }
        }

        return array_map(fn (array $ids) => array_key_first($ids), array_filter($candidates, fn (array $ids) => count($ids) === 1));
    }

    private function storeSites(Server $server, array $sites, array $nodeApps, array $containers): void
    {
        foreach ($sites as $data) {
            // A Node or Docker site proxies to a local port: the app listening there serves it.
            $port = in_array($data['type'], ['node', 'docker'], true) ? parse_url((string) $data['upstream'], PHP_URL_PORT) : null;
            $site = $server->sites()->create(array_merge(Arr::except($data, ['db_refs', 'cert_expires_at']), [
                'cert_expires_at' => isset($data['cert_expires_at']) ? Carbon::parse($data['cert_expires_at']) : null,
                'node_app_id' => $port && $data['type'] === 'node' ? ($nodeApps[$port] ?? null) : null,
                'docker_container_id' => $port && $data['type'] === 'docker' ? ($containers[$port] ?? null) : null,
            ]));
            foreach ($data['db_refs'] as $ref) {
                $site->dbRefs()->create(Arr::only($ref, ['db_name', 'db_user', 'db_host', 'source']));
            }
        }
    }

    private function storeFirewallRules(Server $server, array $rules): void
    {
        foreach (array_values($rules) as $position => $rule) {
            $server->firewallRules()->create([
                'position' => $position + 1,
                'destination' => $rule['to'],
                'action' => $rule['action'],
                'source' => $rule['from'],
                'ipv6' => $rule['ipv6'],
                'comment' => $rule['comment'] ?? null,
            ]);
        }
    }

    private function storeMysql(Server $server, ?array $mysql): void
    {
        if ($mysql === null) {
            return;
        }

        $databases = [];
        foreach ($mysql['databases'] as $db) {
            $databases[$db['name']] = $server->databases()->create($db)->id;
        }

        $accounts = [];
        foreach ($mysql['users'] as $user) {
            $key = ($user['user'] ?? '').'@'.($user['host'] ?? '');
            $accounts[$key] ??= $server->dbUsers()->create(['user' => $user['user'] ?? '', 'host' => $user['host'] ?? '']);
        }

        foreach ($mysql['grants'] as $grant) {
            $account = $accounts[($grant['user'] ?? '').'@'.($grant['host'] ?? '')] ?? null;
            $databaseId = $grant['database'] !== null ? ($databases[$grant['database']] ?? null) : null;
            // A grant on a pattern or a missing database has nothing to point at.
            if (! $account || (! $grant['global'] && $databaseId === null)) {
                continue;
            }
            $account->grants()->create([
                'database_id' => $databaseId,
                'privileges' => $grant['privileges'],
                'global' => $grant['global'],
            ]);
        }
    }
}
