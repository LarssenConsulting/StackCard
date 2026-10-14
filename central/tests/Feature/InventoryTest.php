<?php

use App\Models\Database;
use App\Models\DbGrant;
use App\Models\DbUser;
use App\Models\Server;
use App\Models\Site;
use App\Models\Snapshot;

function sendInventory($test, string $token, array $snapshot)
{
    return $test->withToken($token)->postJson('/api/v1/inventory', $snapshot);
}

it('stores the snapshot and the current state of the server', function () {
    [$server, $token] = enrolledServer();

    sendInventory($this, $token, snapshotFixture())->assertOk()->assertJson(['status' => 'ok']);

    $server->refresh();
    expect($server->hostname)->toBe('web01')
        ->and($server->fqdn)->toBe('web01.example.lan')
        ->and($server->primary_ip)->toBe('10.0.0.11')
        ->and($server->os)->toBe('Ubuntu 24.04.1 LTS')
        ->and($server->virtualization)->toBe('kvm')
        ->and($server->apache_version)->toBe('Apache/2.4.58 (Ubuntu)')
        ->and($server->mysql_version)->toBe('10.11.8-MariaDB')
        ->and($server->agent_version)->toBe('0.1.0')
        ->and($server->last_seen_at)->not->toBeNull()
        ->and($server->last_source_ip)->toBe('127.0.0.1')
        ->and($server->name)->toBe('web01');

    expect($server->ips()->orderBy('id')->pluck('address')->all())->toBe(['10.0.0.11', '2001:db8::11'])
        ->and($server->ips()->first()->prefix)->toBe(24)
        ->and($server->databases()->orderBy('id')->pluck('name')->all())->toBe(['shop_prod', 'orphan_db'])
        ->and($server->dbUsers()->count())->toBe(3);

    $shop = $server->sites()->where('port', 443)->where('server_name', 'shop.example.com')->sole();
    expect($shop->type)->toBe('php-fpm')
        ->and($shop->system_user)->toBe('shop')
        ->and($shop->aliases)->toBe(['www.shop.example.com'])
        ->and($shop->cert_expires_at->toDateString())->toBe('2027-01-01')
        ->and($shop->dbRefs()->sole()->db_name)->toBe('shop_prod');

    $grant = DbGrant::whereHas('dbUser', fn ($q) => $q->where('user', 'shop'))->sole();
    expect($grant->database->name)->toBe('shop_prod')
        ->and($grant->privileges)->toBe(['SELECT', 'INSERT']);
    expect(DbGrant::where('global', true)->sole()->database_id)->toBeNull();

    $snapshot = $server->snapshots()->sole();
    expect($snapshot->schema_version)->toBe(1)
        ->and($snapshot->error_count)->toBe(1)
        ->and($snapshot->source_ip)->toBe('127.0.0.1')
        ->and($snapshot->payload['server']['hostname'])->toBe('web01')
        ->and($snapshot->warnings)->toBe([]);
});

it('keeps the same domain on ports 80 and 443 as two sites', function () {
    [$server, $token] = enrolledServer();
    sendInventory($this, $token, snapshotFixture())->assertOk();

    expect($server->sites()->where('server_name', 'shop.example.com')->pluck('port')->sort()->values()->all())
        ->toBe([80, 443]);
});

it('replaces the current state on each snapshot and keeps the history', function () {
    [$server, $token] = enrolledServer();
    sendInventory($this, $token, snapshotFixture())->assertOk();

    sendInventory($this, $token, snapshotFixture(function ($s) {
        $s['apache']['sites'] = array_values(array_filter(
            $s['apache']['sites'], fn ($site) => $site['server_name'] !== 'api.example.com'
        ));
        $s['mysql']['databases'] = [['name' => 'shop_prod', 'size_bytes' => 2048]];

        return $s;
    }))->assertOk();

    expect($server->sites()->where('server_name', 'api.example.com')->exists())->toBeFalse()
        ->and($server->sites()->count())->toBe(2)
        ->and($server->databases()->sole()->size_bytes)->toBe(2048)
        ->and($server->dbUsers()->count())->toBe(3)
        ->and($server->snapshots()->count())->toBe(2);
});

it('accepts a server without Apache or MySQL', function () {
    [$server, $token] = enrolledServer();

    sendInventory($this, $token, snapshotFixture(fn ($s) => array_merge($s, ['apache' => null, 'mysql' => null])))
        ->assertOk();

    expect($server->sites()->count())->toBe(0)
        ->and($server->refresh()->apache_version)->toBeNull();
});

it('accepts Apache without sites and MySQL without databases, accounts or grants', function () {
    [$server, $token] = enrolledServer();

    sendInventory($this, $token, snapshotFixture(function ($s) {
        $s['apache']['sites'] = [];
        $s['mysql'] = ['version' => '8.0.39', 'databases' => [], 'users' => [], 'grants' => []];

        return $s;
    }))->assertOk();

    expect($server->refresh()->mysql_version)->toBe('8.0.39');
});

it('rejects a request without a token', function () {
    $this->postJson('/api/v1/inventory', snapshotFixture())->assertUnauthorized();
    expect(Snapshot::count())->toBe(0);
});

it('rejects a revoked agent token', function () {
    [$server, $token] = enrolledServer();
    $server->tokens()->delete();

    sendInventory($this, $token, snapshotFixture())->assertUnauthorized();
});

it('rejects a token without the inventory ability', function () {
    [$server] = enrolledServer();
    $token = $server->createToken('other', ['something:else'])->plainTextToken;

    sendInventory($this, $token, snapshotFixture())->assertForbidden();
});

it('validates the snapshot', function (callable $change, string $field) {
    [, $token] = enrolledServer();

    sendInventory($this, $token, snapshotFixture($change))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'unknown schema' => [fn ($s) => array_merge($s, ['schema_version' => 2]), 'schema_version'],
    'missing server' => [function ($s) { unset($s['server']); return $s; }, 'server'],
    'bad site type' => [function ($s) { $s['apache']['sites'][0]['type'] = 'perl'; return $s; }, 'apache.sites.0.type'],
    'bad port' => [function ($s) { $s['apache']['sites'][0]['port'] = 70000; return $s; }, 'apache.sites.0.port'],
    'bad ip' => [function ($s) { $s['server']['primary_ip'] = 'not-an-ip'; return $s; }, 'server.primary_ip'],
]);

it('does not store fields outside the schema', function () {
    [$server, $token] = enrolledServer();

    sendInventory($this, $token, snapshotFixture(function ($s) {
        $s['injected'] = 'x';
        $s['server']['password'] = 'should-not-be-kept';

        return $s;
    }))->assertOk();

    $payload = json_encode($server->snapshots()->sole()->payload);
    expect($payload)->not->toContain('injected')->not->toContain('should-not-be-kept');
});

it('warns when the machine id differs from the enrolled one', function () {
    [$server, $token] = enrolledServer(machineId: 'abc123');

    sendInventory($this, $token, snapshotFixture(function ($s) {
        $s['server']['machine_id'] = 'zzz999';

        return $s;
    }))->assertOk();

    expect($server->snapshots()->sole()->warnings[0])->toContain('zzz999')
        ->and($server->refresh()->machine_id)->toBe('abc123');
});

it('refuses payloads over 5 MB', function () {
    [, $token] = enrolledServer();
    $snapshot = snapshotFixture();
    $snapshot['errors'] = [['collector' => 'apache', 'message' => str_repeat('x', 5 * 1024 * 1024)]];

    sendInventory($this, $token, $snapshot)->assertStatus(413);
});

it('rate-limits each agent token', function () {
    [, $token] = enrolledServer();
    $snapshot = snapshotFixture(fn ($s) => array_merge($s, ['apache' => null, 'mysql' => null]));

    foreach (range(1, 30) as $_) {
        sendInventory($this, $token, $snapshot)->assertOk();
    }
    sendInventory($this, $token, $snapshot)->assertTooManyRequests();
});
