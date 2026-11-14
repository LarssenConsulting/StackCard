<?php

namespace App\Actions;

use App\Models\DockerContainer;
use App\Models\NodeApp;
use App\Models\Server;
use App\Models\ServerSection;
use App\Models\ServerUpdate;
use App\Models\Snapshot;
use App\Models\SiteDomain;
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
        // Sections can weigh megabytes: they live in server_sections, not in the hourly payload.
        $sections = $snapshot['sections'] ?? [];
        unset($snapshot['sections']);

        $record = $server->snapshots()->create([
            'received_at' => now(),
            'source_ip' => $sourceIp,
            'schema_version' => $snapshot['schema_version'],
            'payload' => $snapshot,
            'error_count' => count($snapshot['errors']),
            'warnings' => $this->warnings($server, $snapshot),
        ]);

        $info = $snapshot['server'];
        // The slug becomes permanent at the very first inventory, never afterwards.
        $firstInventory = $server->last_seen_at === null;
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
        if ($firstInventory && $server->slug_locked_at === null) {
            $server->forceFill(['slug_locked_at' => now()]);
        }
        // The first IP an agent reports is trusted; a later change waits for an admin (ApproveIpAction).
        if ($server->approved_ip === null && $server->primary_ip !== null) {
            $server->forceFill(['approved_ip' => $server->primary_ip]);
        }
        $server->save();

        $this->clearState($server);
        $this->storeIps($server, $info['interfaces']);
        $nodeApps = $this->storeNodeApps($server, $snapshot['node']['apps'] ?? []);
        $containers = $this->storeDockerContainers($server, $snapshot['docker']['containers'] ?? []);
        $this->storeSites($server, $snapshot['apache']['sites'] ?? [], $nodeApps, $containers);
        $this->storeFirewallRules($server, $snapshot['ufw']['rules'] ?? []);
        $this->storeMysql($server, $snapshot['mysql'] ?? null);
        $this->storeSections($server, $sections);
        $this->storeUpdates($server, $sections);

        return $record;
    }

    /**
     * Keep a history per section: a new row only when the state changed, otherwise the latest row
     * is just marked as seen again. skipped_not_due means "not collected this time" and writes nothing.
     */
    private function storeSections(Server $server, array $sections): void
    {
        foreach (ServerSection::NAMES as $name) {
            $envelope = $sections[$name] ?? null;
            if ($envelope === null || ($envelope['error'] ?? null) === 'skipped_not_due') {
                continue;
            }

            $error = $envelope['error'] ?? null;
            $data = $error === null ? ($envelope['data'] ?? null) : null;
            $sha256 = $data === null ? null : ($envelope['sha256'] ?? null);
            $sourceErrors = $envelope['source_errors'] ?? [];
            // Same encoding as the agent's cap, so a section it let through is not refused here.
            $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
            if (strlen(json_encode($data, $flags)) + strlen(json_encode($sourceErrors, $flags)) > ServerSection::MAX_BYTES) {
                [$error, $data, $sha256, $sourceErrors] = ['too_large', null, null, []];
            }
            $collectedAt = isset($envelope['collected_at']) ? Carbon::parse($envelope['collected_at']) : null;

            $latest = $server->sections()->select('id', 'sha256', 'error', 'source_errors', 'collected_at')
                ->where('section', $name)->orderByDesc('id')->first();
            if ($latest && $latest->sha256 === $sha256 && $latest->error === $error
                && $this->canonical($latest->source_errors ?? []) === $this->canonical($sourceErrors)) {
                $latest->update(['collected_at' => $collectedAt ?? $latest->collected_at, 'last_seen_at' => now()]);

                continue;
            }

            $server->sections()->create([
                'section' => $name,
                'collected_at' => $collectedAt,
                'sha256' => $sha256,
                'error' => $error,
                'source_errors' => $sourceErrors,
                'data' => $data,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]);
        }
    }

    /**
     * Derive server_updates and the summary columns from the apt_updates and kernel sections. A section
     * in error (or without a list) keeps the previous rows and counts and only records why. A lone
     * `apt_update` source error is not a failure: the age of the cache shows it.
     */
    private function storeUpdates(Server $server, array $sections): void
    {
        $apt = $sections['apt_updates'] ?? null;
        if ($apt !== null && ($apt['error'] ?? null) !== 'skipped_not_due') {
            $error = $apt['error'] ?? null;
            $data = $error === null ? ($apt['data'] ?? null) : null;
            $upgrades = is_array($data) ? ($data['upgrades'] ?? null) : null;

            if ($error === null && is_array($upgrades)) {
                $rows = $this->cleanUpgrades($upgrades);
                $server->updates()->delete();
                $now = now();
                foreach (array_chunk($rows, 500) as $chunk) {
                    ServerUpdate::insert(array_map(fn (array $row) => array_merge($row, [
                        'server_id' => $server->id,
                        'origins' => json_encode($row['origins']),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]), $chunk));
                }
                $cache = $data['cache_updated_at'] ?? null;
                try {
                    $cacheAt = is_string($cache) && $cache !== '' ? Carbon::parse($cache) : null;
                } catch (\Throwable) {
                    $cacheAt = null;
                }
                $server->forceFill([
                    'updates_count' => count($rows),
                    'security_updates_count' => count(array_filter($rows, fn (array $row) => $row['security'])),
                    'apt_cache_updated_at' => $cacheAt,
                    'updates_received_at' => now(),
                    // Lines the agent could not read: the list may be incomplete, never show « à jour ».
                    'updates_error' => is_string($unparsed = $apt['source_errors']['upgrades_unparsed'] ?? null) && $unparsed !== ''
                        ? mb_substr($unparsed, 0, 4000) : null,
                ]);
            } else {
                $sourceError = $apt['source_errors']['upgrades'] ?? null;
                $message = $error ?? (is_string($sourceError) && $sourceError !== '' ? $sourceError : 'upgrades');
                $server->forceFill(['updates_error' => mb_substr($message, 0, 4000)]);
            }
        }

        $kernel = $sections['kernel'] ?? null;
        if ($kernel !== null && ($kernel['error'] ?? null) === null && is_array($kernel['data'] ?? null)) {
            $reboot = $kernel['data']['reboot_required'] ?? null;
            $server->forceFill(['reboot_required' => is_bool($reboot) ? $reboot : null]);
        }

        $server->save();
    }

    /**
     * The agent's list is opaque data: keep only well-formed entries, trimmed to the column sizes.
     * Deduplicated on the trimmed (package, arch), like the unique index; the last entry wins.
     *
     * @return array<string, array{package: string, arch: string, installed_version: ?string, candidate_version: string, security: bool, origins: array<int, string>}>
     */
    /** Same rule as the agent's `_suite`: what follows the last "/", else the last ":". */
    private static function suite(string $origin): string
    {
        foreach (['/', ':'] as $separator) {
            $position = strrpos($origin, $separator);
            if ($position !== false) {
                $suite = trim(substr($origin, $position + 1));

                return $suite !== '' ? $suite : $origin;
            }
        }

        return $origin;
    }

    private function cleanUpgrades(array $upgrades): array
    {
        $text = fn (mixed $value, int $max): ?string => is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, $max) : null;

        $rows = [];
        foreach ($upgrades as $upgrade) {
            if (! is_array($upgrade)) {
                continue;
            }
            $package = $text($upgrade['package'] ?? null, 255);
            $candidate = $text($upgrade['candidate'] ?? null, 255);
            if ($package === null || $candidate === null) {
                continue;
            }
            $arch = $text($upgrade['arch'] ?? null, 32) ?? '';
            $origins = [];
            if (is_array($upgrade['origins'] ?? null)) {
                foreach ($upgrade['origins'] as $origin) {
                    if (($origin = $text($origin, 255)) !== null) {
                        // Agents 0.6.0 kept some origins whole (". nodistro:nodistro"): keep the suite only.
                        $origins[] = self::suite($origin);
                    }
                    if (count($origins) >= 50) {
                        break;
                    }
                }
            }
            $rows[$package."\0".$arch] = [
                'package' => $package,
                'arch' => $arch,
                'installed_version' => $text($upgrade['installed'] ?? null, 255),
                'candidate_version' => $candidate,
                'security' => ($upgrade['security'] ?? false) === true,
                'origins' => $origins,
            ];
        }

        return array_values($rows);
    }

    /** JSON with object keys sorted at every level, so key order does not matter. */
    private function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort) {
            if (! is_array($item)) {
                return $item;
            }
            $item = array_map($sort, $item);
            if (! array_is_list($item)) {
                ksort($item);
            }

            return $item;
        };

        return json_encode($sort($value));
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
            foreach (SiteDomain::namesFor($data['server_name'] ?? null, $data['aliases'] ?? []) as $domain => $role) {
                $site->domains()->create(['server_id' => $server->id, 'domain' => $domain, 'role' => $role]);
            }
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
