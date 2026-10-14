<?php

namespace App\Actions;

use App\Models\Server;
use App\Models\Snapshot;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Store a validated snapshot: keep it as history, then replace the server's
 * current state (IPs, sites, databases, accounts, grants) in one transaction.
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
            'agent_version' => $snapshot['agent_version'] ?? null,
            'last_seen_at' => now(),
            'last_source_ip' => $sourceIp,
        ]);

        $this->clearState($server);
        $this->storeIps($server, $info['interfaces']);
        $this->storeSites($server, $snapshot['apache']['sites'] ?? []);
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

    private function storeSites(Server $server, array $sites): void
    {
        foreach ($sites as $data) {
            $site = $server->sites()->create(array_merge(Arr::except($data, ['db_refs', 'cert_expires_at']), [
                'cert_expires_at' => isset($data['cert_expires_at']) ? Carbon::parse($data['cert_expires_at']) : null,
            ]));
            foreach ($data['db_refs'] as $ref) {
                $site->dbRefs()->create(Arr::only($ref, ['db_name', 'db_user', 'db_host', 'source']));
            }
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
