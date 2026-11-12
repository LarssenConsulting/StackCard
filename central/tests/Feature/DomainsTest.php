<?php

use App\Models\Server;
use App\Models\SiteDomain;

beforeEach(function () {
    [$this->server, $this->agentToken] = enrolledServer();
    $this->server->update(['slug' => 'web01', 'last_seen_at' => now(), 'notes' => 'secret note']);
    [$this->client, $this->token] = readClient();
});

function ingestAs($test, string $token, ?callable $change = null): void
{
    app('auth')->forgetGuards();
    $test->withToken($token)->postJson('/api/v1/inventory', snapshotFixture($change))->assertOk();
}

function domainEntry($response, string $domain): ?array
{
    return collect($response->json('data'))->firstWhere('domain', $domain);
}

it('lists a domain once with one location per port, and flags aliases', function () {
    ingestAs($this, $this->agentToken);

    $response = readApi($this, $this->token, 'domains')->assertOk();
    $domains = collect($response->json('data'))->pluck('domain')->all();
    $sorted = $domains;
    sort($sorted);

    expect($domains)->toBe($sorted)
        ->and(array_count_values($domains)['shop.example.com'])->toBe(1);

    $shop = domainEntry($response, 'shop.example.com');
    expect(collect($shop['locations'])->pluck('port')->all())->toBe([80, 443])
        ->and($shop['locations'][0])->toMatchArray(['source' => 'apache', 'role' => 'server_name', 'server_stale' => false])
        ->and($shop['locations'][0]['server'])->toBe(['slug' => 'web01', 'name' => 'web01'])
        ->and($shop['locations'][1]['document_root'])->toBe('/var/www/shop/public');

    expect(domainEntry($response, 'www.shop.example.com')['locations'][0]['role'])->toBe('alias');
});

it('lower-cases domains and keeps server_name when a name is also an alias', function () {
    expect(SiteDomain::namesFor('Shop.Example.com', ['WWW.Shop.Example.com', 'SHOP.example.com', '  ', 'www.shop.example.com']))
        ->toBe(['shop.example.com' => 'server_name', 'www.shop.example.com' => 'alias']);

    ingestAs($this, $this->agentToken, function ($snapshot) {
        $snapshot['apache']['sites'][0]['aliases'] = ['WWW.Shop.Example.com'];
        $snapshot['apache']['sites'][1]['aliases'] = ['WWW.Shop.Example.com'];

        return $snapshot;
    });

    $response = readApi($this, $this->token, 'domains')->assertOk();
    $names = collect($response->json('data'))->pluck('domain')->all();

    expect(array_count_values($names)['www.shop.example.com'])->toBe(1)
        ->and($names)->not->toContain('WWW.Shop.Example.com');
});

it('does not accumulate rows when the same inventory is received twice', function () {
    ingestAs($this, $this->agentToken);
    $first = SiteDomain::count();
    ingestAs($this, $this->agentToken);

    expect($first)->toBeGreaterThan(0)->and(SiteDomain::count())->toBe($first);
});

it('merges two servers serving the same domain, skips revoked ones and filters by server', function () {
    [$other, $otherToken] = enrolledServer('web02', 'def456');
    $other->update(['slug' => 'web02', 'last_seen_at' => now()]);
    ingestAs($this, $this->agentToken);
    ingestAs($this, $otherToken);

    $shop = domainEntry(readApi($this, $this->token, 'domains')->assertOk(), 'shop.example.com');
    expect(collect($shop['locations'])->pluck('server.slug')->all())->toBe(['web01', 'web01', 'web02', 'web02']);

    $only = readApi($this, $this->token, 'domains?server=web02')->assertOk();
    expect(collect($only->json('data.0.locations'))->pluck('server.slug')->unique()->all())->toBe(['web02']);

    readApi($this, $this->token, 'domains?server=nope')->assertOk()->assertExactJson(['data' => []]);

    $other->update(['status' => Server::STATUS_REVOKED]);
    $shop = domainEntry(readApi($this, $this->token, 'domains')->assertOk(), 'shop.example.com');
    expect(collect($shop['locations'])->pluck('server.slug')->unique()->all())->toBe(['web01']);
});

it('flags the locations of a server silent for too long', function () {
    ingestAs($this, $this->agentToken);
    $this->server->update(['last_seen_at' => now()->subHours(5)]);

    $shop = domainEntry(readApi($this, $this->token, 'domains'), 'shop.example.com');
    expect($shop['locations'][0]['server_stale'])->toBeTrue();
});

it('requires a token and an allowed address', function () {
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/domains')->assertUnauthorized();

    readApi($this, $this->token, 'domains', '198.51.100.7')->assertForbidden();
});

it('lists its fields one by one and never exposes notes or the machine id', function () {
    ingestAs($this, $this->agentToken);
    $response = readApi($this, $this->token, 'domains')->assertOk();
    $body = $response->getContent();

    expect($body)->toContain('document_root')->not->toContain('secret note')->not->toContain('abc123')->not->toContain('machine_id')
        ->and(array_keys(domainEntry($response, 'shop.example.com')))->toBe(['domain', 'locations'])
        ->and(array_keys(domainEntry($response, 'shop.example.com')['locations'][0]))->toBe([
            'source', 'server', 'server_stale', 'role', 'port', 'https', 'cert_expires_at', 'type',
            'document_root', 'app_dir', 'upstream', 'php_version', 'technologies',
        ]);
});

it('deletes the domains with their server', function () {
    ingestAs($this, $this->agentToken);
    expect(SiteDomain::count())->toBeGreaterThan(0);

    $this->server->sites()->delete();

    expect(SiteDomain::count())->toBe(0);
});

it('refuses a non-string server filter with a 422', function () {
    $this->withToken($this->token)->withServerVariables(['REMOTE_ADDR' => ALLOWED_IP])
        ->getJson('/api/v1/domains?server[]=x')
        ->assertStatus(422)->assertJsonStructure(['message']);
});
