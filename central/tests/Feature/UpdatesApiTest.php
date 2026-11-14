<?php

use App\Models\Server;

/** Ingest the fixture for a server (cache one hour old, so the state is `ok`) and return it fresh. */
function ingestedServer(string $name, string $machineId, ?callable $change = null): array
{
    [$server, $agentToken] = enrolledServer($name, $machineId);
    app('auth')->forgetGuards();
    test()->withToken($agentToken)->postJson('/api/v1/inventory', snapshotFixture(function (array $s) use ($name, $change) {
        $s['server']['hostname'] = $name;
        $s['sections']['apt_updates']['data']['cache_updated_at'] = now()->subHour()->toIso8601ZuluString();

        return $change ? $change($s) : $s;
    }))->assertOk();

    return [$server->fresh(), $agentToken];
}

beforeEach(function () {
    [$this->client, $this->token] = readClient();
});

it('adds the update summary to the server list', function () {
    [$server] = ingestedServer('web01', 'm1');
    [$bare] = enrolledServer('bare01', 'm2');

    $data = collect(readApi($this, $this->token, 'servers')->assertOk()->json('data'))->keyBy('name');

    expect($data['web01'])->toMatchArray([
        'updates_state' => 'ok', 'updates_pending' => 2, 'security_updates_pending' => 1, 'reboot_required' => true,
    ])->and($data['web01']['apt_cache_updated_at'])->not->toBeNull()
        ->and($data['bare01']['updates_state'])->toBe('unknown')
        ->and($data['bare01']['updates_pending'])->toBeNull()
        ->and($data['bare01']['security_updates_pending'])->toBeNull();
});

it('details the updates of one server, sorted, with held and reboot packages', function () {
    [$server] = ingestedServer('web01', 'm1');

    $res = readApi($this, $this->token, "servers/{$server->slug}/updates")->assertOk()
        ->assertJsonPath('data.slug', $server->slug)
        ->assertJsonPath('data.updates_state', 'ok')
        ->assertJsonPath('data.reboot_required', true)
        ->assertJsonPath('data.reboot_required_packages', ['linux-image-6.8.0-48-generic'])
        ->assertJsonPath('data.held', ['php8.3-fpm'])
        ->assertJsonPath('data.updates_error', null);

    expect(array_column($res->json('data.updates'), 'package'))->toBe(['libssl3t64', 'tzdata'])
        ->and($res->json('data.updates.0'))->toBe([
            'package' => 'libssl3t64', 'arch' => 'amd64', 'installed_version' => '3.0.13-0ubuntu3.4',
            'candidate_version' => '3.0.13-0ubuntu3.5', 'security' => true, 'origins' => ['noble-security'],
        ]);
});

it('exposes the collection date and source errors of the apt_updates section', function () {
    [$server] = ingestedServer('web01', 'm1', function (array $s) {
        $s['sections']['apt_updates']['source_errors'] = ['apt_update' => 'locked'];

        return $s;
    });

    $res = readApi($this, $this->token, "servers/{$server->slug}/updates")->assertOk()
        ->assertJsonPath('data.source_errors', ['apt_update' => 'locked']);
    expect($res->json('data.collected_at'))->not->toBeNull();

    [$bare] = enrolledServer('bare01', 'm2');
    readApi($this, $this->token, "servers/{$bare->slug}/updates")->assertOk()
        ->assertJsonPath('data.collected_at', null)->assertJsonPath('data.source_errors', null);
});

it('answers 404 for an unknown server', function () {
    readApi($this, $this->token, 'servers/nope/updates')->assertNotFound();
});

it('lists the fleet updates, filtered on security', function () {
    ingestedServer('web01', 'm1');
    readApi($this, $this->token, 'updates?security=1')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.package', 'libssl3t64')
        ->assertJsonPath('data.0.security', true);

    readApi($this, $this->token, 'updates')->assertOk()->assertJsonCount(2, 'data');
    readApi($this, $this->token, 'updates?package=tzdata')->assertOk()->assertJsonCount(1, 'data');
});

it('groups servers sharing a candidate version and leaves revoked ones out', function () {
    [$a] = ingestedServer('web01', 'm1');
    [$b] = ingestedServer('web02', 'm2');
    [$c] = ingestedServer('web03', 'm3');
    $c->update(['status' => Server::STATUS_REVOKED]);

    $res = readApi($this, $this->token, 'updates?package=libssl3t64')->assertOk();

    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.installed_versions'))->toBe(['3.0.13-0ubuntu3.4'])
        ->and(array_column($res->json('data.0.servers'), 'slug'))->toBe([$a->slug, $b->slug])
        ->and($res->json('data.0.servers.0'))->toHaveKeys(['slug', 'name', 'installed_version', 'updates_state']);
});

it('rejects non-string fleet filters', function () {
    readApi($this, $this->token, 'updates?package[]=x')->assertStatus(422);
    readApi($this, $this->token, 'updates?security[]=1')->assertStatus(422);
});

it('looks a package up across the fleet, pending or up to date', function () {
    ingestedServer('web01', 'm1');
    ingestedServer('web02', 'm2');
    [$gone] = ingestedServer('web03', 'm3');
    $gone->update(['status' => Server::STATUS_REVOKED]);

    // libssl3t64 is pending on both servers.
    $res = readApi($this, $this->token, 'packages?name=libssl3t64')->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0'))->toMatchArray([
            'name' => 'web01', 'installed_version' => '3.0.13-0ubuntu3.4', 'candidate_version' => '3.0.13-0ubuntu3.5',
            'security' => true, 'updates_state' => 'ok',
        ])->and($res->json('data.0'))->toHaveKeys(['slug', 'os', 'apt_cache_updated_at', 'packages_collected_at']);

    // apache2 is installed and up to date.
    $res = readApi($this, $this->token, 'packages?name=apache2')->assertOk();
    expect($res->json('data'))->toHaveCount(2)
        ->and($res->json('data.0.installed_version'))->toBe('2.4.58-1ubuntu8.5')
        ->and($res->json('data.0.candidate_version'))->toBeNull()
        ->and($res->json('data.0.security'))->toBeNull()
        ->and($res->json('data.0.updates_state'))->toBe('ok')
        ->and($res->json('data.0.packages_collected_at'))->not->toBeNull();

    readApi($this, $this->token, 'packages?name=nosuchpkg')->assertOk()->assertJsonPath('data', []);
});

it('rejects an invalid package name with a French 422', function () {
    foreach (['packages', 'packages?name[]=x', 'packages?name=Bad%20Name', 'packages?name=', 'packages?name=open%0Assl'] as $path) {
        readApi($this, $this->token, $path)->assertStatus(422)->assertJsonStructure(['message']);
    }
    expect(readApi($this, $this->token, 'packages')->json('message'))->toContain('obligatoire');
});

it('keeps the update routes behind the read API guards', function () {
    ingestedServer('web01', 'm1');
    foreach (['updates', 'packages?name=apache2', 'servers/web01/updates'] as $path) {
        app('auth')->forgetGuards();
        $this->flushHeaders()->withServerVariables(['REMOTE_ADDR' => ALLOWED_IP])->getJson("/api/v1/{$path}")->assertUnauthorized();
        readApi($this, $this->token, $path, '198.51.100.7')->assertForbidden();
    }
});

it('never exposes notes or the machine id', function () {
    [$server] = ingestedServer('web01', 'machine-secret-id');
    $server->update(['notes' => 'secret note']);

    foreach (['updates', 'packages?name=apache2', "servers/{$server->slug}/updates", 'servers'] as $path) {
        expect(readApi($this, $this->token, $path)->getContent())
            ->not->toContain('secret note')->not->toContain('machine-secret-id')->not->toContain('machine_id');
    }
});

it('falls back on the latest valid packages section when the newest one is an error', function () {
    [$server] = ingestedServer('web01', 'm1');
    $valid = $server->sections()->where('section', 'packages')->orderByDesc('id')->first();
    $server->sections()->create([
        'section' => 'packages', 'collected_at' => now(), 'sha256' => null, 'error' => 'timeout',
        'source_errors' => [], 'data' => null, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    $res = readApi($this, $this->token, 'packages?name=apache2')->assertOk();
    expect($res->json('data'))->toHaveCount(1)
        ->and($res->json('data.0.installed_version'))->toBe('2.4.58-1ubuntu8.5')
        ->and($res->json('data.0.packages_collected_at'))->toStartWith('2026-11-01T08:00:00');
});

it('takes the reboot packages from the latest valid kernel section', function () {
    [$server] = ingestedServer('web01', 'm1');
    $server->sections()->create([
        'section' => 'kernel', 'collected_at' => now(), 'sha256' => null, 'error' => 'timeout',
        'source_errors' => [], 'data' => null, 'first_seen_at' => now(), 'last_seen_at' => now(),
    ]);

    readApi($this, $this->token, "servers/{$server->slug}/updates")->assertOk()
        ->assertJsonPath('data.reboot_required_packages', ['linux-image-6.8.0-48-generic']);
});
