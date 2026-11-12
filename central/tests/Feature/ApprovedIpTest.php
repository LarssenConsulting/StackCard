<?php

use App\Filament\Resources\Servers\Pages\ViewServer;
use App\Models\Server;
use Livewire\Livewire;

/** POST the reference inventory with the agent's reported primary IP replaced. */
function ingestWithIp(string $token, string $ip)
{
    app('auth')->forgetGuards();

    return test()->withToken($token)->postJson('/api/v1/inventory', snapshotFixture(function (array $s) use ($ip) {
        $s['server']['primary_ip'] = $ip;

        return $s;
    }))->assertOk();
}

/** An active server whose agent reported 203.0.113.5, then 198.51.100.9. */
function serverWithChangedIp(): Server
{
    [$server, $token] = enrolledServer();
    ingestWithIp($token, '203.0.113.5');
    ingestWithIp($token, '198.51.100.9');

    return $server->refresh();
}

it('pins the first reported ip and keeps it when the agent reports another one', function () {
    $server = serverWithChangedIp();

    expect($server->approved_ip)->toBe('203.0.113.5')
        ->and($server->primary_ip)->toBe('198.51.100.9')
        ->and($server->ipChangePending())->toBeTrue()
        ->and($server->trustedAddress())->toBeNull();
});

it('trusts the manual connection address whatever the agent reports', function () {
    $server = serverWithChangedIp();
    $server->update(['connect_address' => '10.8.0.5']);

    expect($server->trustedAddress())->toBe('10.8.0.5');
});

it('leaves a server with an unapproved ip out of the Ansible inventory', function () {
    serverWithChangedIp();
    [, $readToken] = readClient();

    $inventory = readApi($this, $readToken, 'inventory/ansible')->assertOk();

    expect($inventory->json('all.hosts'))->toBe([]);
});

it('lists the server again once an admin approves the new ip', function () {
    $server = serverWithChangedIp();
    $this->actingAs(adminUser(), 'web');

    Livewire::test(ViewServer::class, ['record' => $server->getKey()])
        ->assertActionVisible('approveIp')
        ->callAction('approveIp')
        ->assertNotified('IP 198.51.100.9 validée');

    expect($server->refresh()->approved_ip)->toBe('198.51.100.9');

    [, $readToken] = readClient();
    readApi($this, $readToken, 'inventory/ansible')
        ->assertJsonPath('_meta.hostvars.web01.ansible_host', '198.51.100.9');
});

it('hides the approval from a plain user', function () {
    $server = serverWithChangedIp();
    $this->actingAs(plainUser(), 'web');

    Livewire::test(ViewServer::class, ['record' => $server->getKey()])->assertActionHidden('approveIp');

    expect($server->refresh()->approved_ip)->toBe('203.0.113.5');
});

it('exposes the approved ip in the read API', function () {
    serverWithChangedIp();
    [, $readToken] = readClient();

    readApi($this, $readToken, 'servers')
        ->assertJsonPath('data.0.approved_ip', '203.0.113.5')
        ->assertJsonPath('data.0.primary_ip', '198.51.100.9');
});
