<?php

namespace App\Actions;

use App\Models\DbUser;
use App\Models\Server;
use App\Models\SiteDbRef;
use Illuminate\Support\Collection;

/**
 * Resolve every site's database reference to an inventoried database:
 *  - local: the host is the site's own server (loopback, socket, own name or IP);
 *  - remote: the host is the name or IP of another inventoried server;
 *  - unresolved: anything else, or the database is not on the target server.
 *
 * Links depend on every server's latest snapshot, so the whole fleet is
 * re-linked after each ingest (cheap for a fleet of ~10 servers).
 */
class LinkDatabases
{
    private const LOOPBACK = ['', 'localhost', '127.0.0.1', '::1'];

    public function __invoke(): void
    {
        $servers = Server::with(['ips', 'databases', 'dbUsers'])->get()->keyBy('id');
        $byAddress = $this->addressIndex($servers);

        SiteDbRef::with('site:id,server_id')->chunkById(500, function (Collection $refs) use ($servers, $byAddress) {
            foreach ($refs as $ref) {
                $this->link($ref, $servers->get($ref->site->server_id), $byAddress);
            }
        });
    }

    private function addressIndex(Collection $servers): array
    {
        $index = [];
        foreach ($servers as $server) {
            foreach ($server->addresses() as $address) {
                $index[strtolower($address)] ??= $server;
            }
        }

        return $index;
    }

    private function link(SiteDbRef $ref, Server $source, array $byAddress): void
    {
        $host = $this->normalizeHost($ref->db_host);
        $sourceAddresses = array_map('strtolower', $source->addresses());

        if (in_array($host, self::LOOPBACK, true) || in_array($host, $sourceAddresses, true)) {
            [$target, $resolution] = [$source, SiteDbRef::LOCAL];
        } elseif (isset($byAddress[$host])) {
            [$target, $resolution] = [$byAddress[$host], SiteDbRef::REMOTE];
        } else {
            [$target, $resolution] = [null, SiteDbRef::UNRESOLVED];
        }

        $database = $target?->databases->firstWhere('name', $ref->db_name);
        $account = $database ? $this->account($target, $ref->db_user, $resolution, $source) : null;

        $ref->forceFill([
            'database_id' => $database?->id,
            'db_user_id' => $account?->id,
            'resolution' => $database ? $resolution : SiteDbRef::UNRESOLVED,
        ])->save();
    }

    /** "127.0.0.1:3306" -> "127.0.0.1", "localhost:/run/mysqld.sock" and socket paths -> "localhost". */
    private function normalizeHost(?string $host): string
    {
        $host = strtolower(trim($host ?? ''));
        if (str_starts_with($host, '/') || str_starts_with($host, 'localhost:')) {
            return 'localhost';
        }
        if (preg_match('/^\[(.+)\](?::\d+)?$/', $host, $m)) {
            return $m[1];
        }
        if (substr_count($host, ':') === 1) {
            return explode(':', $host)[0];
        }

        return $host;
    }

    /** The account the site connects as: exact client host, then '%', then any. */
    private function account(Server $target, ?string $user, string $resolution, Server $source): ?DbUser
    {
        if ($user === null) {
            return null;
        }
        $candidates = $target->dbUsers->where('user', $user);
        $clientHosts = $resolution === SiteDbRef::LOCAL
            ? ['localhost', '127.0.0.1', '::1']
            : array_map('strtolower', $source->addresses());

        return $candidates->first(fn (DbUser $u) => in_array(strtolower($u->host), $clientHosts, true))
            ?? $candidates->firstWhere('host', '%')
            ?? $candidates->first();
    }
}
