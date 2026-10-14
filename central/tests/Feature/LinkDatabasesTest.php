<?php

use App\Models\Database;
use App\Models\Site;
use App\Models\SiteDbRef;

function ingest($test, string $token, array $snapshot): void
{
    // The test app keeps the authenticated server between requests; agents
    // never share a process in production, so start each request fresh.
    app('auth')->forgetGuards();
    $test->withToken($token)->postJson('/api/v1/inventory', $snapshot)->assertOk();
}

/** A database server db01 (10.0.0.20) holding api_prod for web01's Node app. */
function dbServerSnapshot(): array
{
    return snapshotFixture(function ($s) {
        $s['server'] = array_merge($s['server'], [
            'hostname' => 'db01', 'fqdn' => 'db01.example.lan', 'machine_id' => 'db01-id',
            'primary_ip' => '10.0.0.20', 'interfaces' => [['name' => 'eth0', 'addresses' => ['10.0.0.20/24']]],
        ]);
        $s['apache'] = null;
        $s['mysql']['databases'] = [['name' => 'api_prod', 'size_bytes' => 10]];
        $s['mysql']['users'] = [['user' => 'api', 'host' => '%'], ['user' => 'api', 'host' => '10.0.0.11']];
        $s['mysql']['grants'] = [];

        return $s;
    });
}

function refFor(string $siteName): SiteDbRef
{
    return Site::where('server_name', $siteName)->where('port', 443)->sole()->dbRefs()->sole();
}

it('links a site to a database on the same server', function () {
    [$web, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture());

    $ref = refFor('shop.example.com');
    expect($ref->resolution)->toBe(SiteDbRef::LOCAL)
        ->and($ref->database->name)->toBe('shop_prod')
        ->and($ref->database->server->is($web))->toBeTrue()
        ->and($ref->dbUser->host)->toBe('localhost');
});

it('marks a database on an unknown host as unresolved', function () {
    [, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture());

    $ref = refFor('api.example.com');
    expect($ref->resolution)->toBe(SiteDbRef::UNRESOLVED)
        ->and($ref->database_id)->toBeNull();
});

it('links a site to a database on another inventoried server, whichever reports first', function () {
    [, $webToken] = enrolledServer('web01', 'abc123');
    [$db, $dbToken] = enrolledServer('db01', 'db01-id');

    ingest($this, $webToken, snapshotFixture());
    ingest($this, $dbToken, dbServerSnapshot());

    $ref = refFor('api.example.com');
    expect($ref->resolution)->toBe(SiteDbRef::REMOTE)
        ->and($ref->database->server->is($db))->toBeTrue()
        // the account granted to web01's own address wins over '%'
        ->and($ref->dbUser->host)->toBe('10.0.0.11');

    // db01 reports again: its rows are recreated, the link must survive.
    ingest($this, $dbToken, dbServerSnapshot());
    expect(refFor('api.example.com')->database->server->is($db))->toBeTrue();

    // web01 reports again: its refs are recreated and linked straight away.
    ingest($this, $webToken, snapshotFixture());
    expect(refFor('api.example.com')->resolution)->toBe(SiteDbRef::REMOTE);
});

it('treats sockets, ports and the server own names as local', function (?string $host) {
    [, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture(function ($s) use ($host) {
        $s['apache']['sites'][1]['db_refs'][0]['db_host'] = $host;

        return $s;
    }));

    expect(refFor('shop.example.com')->resolution)->toBe(SiteDbRef::LOCAL);
})->with([
    'null' => [null],
    'empty' => [''],
    'loopback with port' => ['127.0.0.1:3306'],
    'socket' => ['localhost:/var/run/mysqld/mysqld.sock'],
    'socket path' => ['/run/mysqld/mysqld.sock'],
    'ipv6 loopback' => ['::1'],
    'own ip' => ['10.0.0.11'],
    'own fqdn upper case' => ['WEB01.example.lan'],
]);

it('marks a local reference to a missing database as unresolved', function () {
    [, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture(function ($s) {
        $s['apache']['sites'][1]['db_refs'][0]['db_name'] = 'gone';

        return $s;
    }));

    expect(refFor('shop.example.com')->resolution)->toBe(SiteDbRef::UNRESOLVED);
});

it('falls back to the % account, then to any account of that user', function () {
    [, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture(function ($s) {
        $s['mysql']['users'] = [['user' => 'shop', 'host' => '%'], ['user' => 'shop', 'host' => '10.9.9.9']];

        return $s;
    }));
    expect(refFor('shop.example.com')->dbUser->host)->toBe('%');

    ingest($this, $token, snapshotFixture(function ($s) {
        $s['mysql']['users'] = [['user' => 'shop', 'host' => '10.9.9.9']];

        return $s;
    }));
    expect(refFor('shop.example.com')->dbUser->host)->toBe('10.9.9.9');
});

it('does not link through an address claimed by two servers', function () {
    [, $webToken] = enrolledServer('web01', 'abc123');
    [, $dbToken] = enrolledServer('db01', 'db01-id');
    [, $impostorToken] = enrolledServer('impostor', 'imp-id');

    ingest($this, $webToken, snapshotFixture());
    ingest($this, $dbToken, dbServerSnapshot());
    // A compromised agent claims db01's address and a database of the same name.
    ingest($this, $impostorToken, snapshotFixture(function ($s) {
        $s['server'] = array_merge(dbServerSnapshot()['server'], ['hostname' => 'impostor', 'machine_id' => 'imp-id']);
        $s['apache'] = null;
        $s['mysql']['databases'] = [['name' => 'api_prod', 'size_bytes' => 1]];

        return $s;
    }));

    $ref = refFor('api.example.com');
    expect($ref->resolution)->toBe(SiteDbRef::UNRESOLVED)
        ->and($ref->database_id)->toBeNull();
});

it('never links to a revoked server', function () {
    [, $webToken] = enrolledServer('web01', 'abc123');
    [$db, $dbToken] = enrolledServer('db01', 'db01-id');
    ingest($this, $webToken, snapshotFixture());
    ingest($this, $dbToken, dbServerSnapshot());

    $db->update(['status' => \App\Models\Server::STATUS_REVOKED]);
    app(\App\Actions\LinkDatabases::class)();

    expect(refFor('api.example.com')->resolution)->toBe(SiteDbRef::UNRESOLVED);
});

it('ignores loopback names another server claims as its own', function () {
    [, $webToken] = enrolledServer('web01', 'abc123');
    [, $otherToken] = enrolledServer('other', 'other-id');
    ingest($this, $webToken, snapshotFixture(function ($s) {
        $s['apache']['sites'][2]['db_refs'][0]['db_host'] = 'localhost.localdomain';

        return $s;
    }));
    ingest($this, $otherToken, snapshotFixture(function ($s) {
        $s['server'] = array_merge($s['server'], [
            'hostname' => 'localhost.localdomain', 'fqdn' => 'localhost', 'machine_id' => 'other-id',
            'primary_ip' => '127.0.0.1', 'interfaces' => [['name' => 'lo', 'addresses' => ['127.0.0.1/8']]],
        ]);
        $s['apache'] = null;
        $s['mysql']['databases'] = [['name' => 'api_prod', 'size_bytes' => 1]];

        return $s;
    }));

    expect(refFor('api.example.com')->resolution)->toBe(SiteDbRef::UNRESOLVED);
});

it('lists databases no site uses as orphans', function () {
    [, $token] = enrolledServer();
    ingest($this, $token, snapshotFixture());

    expect(Database::orphans()->pluck('name')->all())->toBe(['orphan_db']);
});
