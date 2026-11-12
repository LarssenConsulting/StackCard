<?php

use App\Models\Server;

/** An active server whose reported IP is the approved one (as after its first inventory). */
function ansibleServer(string $name, array $attributes = []): Server
{
    $server = Server::create(array_merge([
        'name' => $name, 'machine_id' => 'mid-'.$name, 'status' => Server::STATUS_ACTIVE,
        'primary_ip' => '203.0.113.5', 'last_seen_at' => now(),
    ], $attributes));
    $server->forceFill(['approved_ip' => $server->primary_ip])->save();

    return $server;
}

beforeEach(function () {
    [$this->client, $this->token] = readClient();
});

it('names the host by its slug with the primary ip', function () {
    ansibleServer('web01', [
        'hostname' => 'web01', 'os' => 'Ubuntu 24.04.1 LTS', 'apache_version' => '2.4.58', 'mysql_version' => '8.0.36',
    ]);

    $response = readApi($this, $this->token, 'inventory/ansible')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('_meta.hostvars.web01.ansible_host', '203.0.113.5')
        ->assertJsonPath('_meta.hostvars.web01.stackcard_name', 'web01')
        ->assertJsonPath('_meta.hostvars.web01.stackcard_os', 'Ubuntu 24.04.1 LTS')
        ->assertJsonPath('_meta.hostvars.web01.stackcard_stale', false)
        ->assertJsonPath('ubuntu_24_04.hosts', ['web01'])
        ->assertJsonPath('apache.hosts', ['web01'])
        ->assertJsonPath('mysql.hosts', ['web01'])
        ->assertJsonPath('all.hosts', ['web01'])
        ->assertJsonPath('all.children', ['apache', 'mysql', 'ubuntu_24_04']);

    expect($response->json())->not->toHaveKey('ungrouped')->not->toHaveKey('docker')->not->toHaveKey('stale')
        ->and($response->json('_meta.hostvars.web01'))->not->toHaveKey('ansible_port');
    expect($response->getContent())->not->toContain('mid-web01')->not->toContain('machine_id');
});

it('uses the manual connection address and port', function () {
    ansibleServer('web01', ['connect_address' => 'web01.vpn.example', 'connect_port' => 2222]);

    readApi($this, $this->token, 'inventory/ansible')
        ->assertJsonPath('_meta.hostvars.web01.ansible_host', 'web01.vpn.example')
        ->assertJsonPath('_meta.hostvars.web01.ansible_port', 2222);
});

it('never sends agent text that Ansible would template', function () {
    ansibleServer('web01', [
        'hostname' => "{{ lookup('pipe', 'id') }}", 'fqdn' => '{% raw %}x', 'os' => 'Ubuntu {# c #} 24.04',
    ]);

    $vars = readApi($this, $this->token, 'inventory/ansible')->assertOk()->json('_meta.hostvars.web01');

    expect($vars['stackcard_hostname'])->toBeNull()
        ->and($vars['stackcard_fqdn'])->toBeNull()
        ->and($vars['stackcard_os'])->toBeNull()
        ->and($vars['stackcard_name'])->toBe('web01');
});

it('leaves out a server an admin excluded, but keeps it in the read API', function () {
    ansibleServer('web01');
    ansibleServer('bastion', ['primary_ip' => '203.0.113.9', 'ansible_excluded' => true]);

    readApi($this, $this->token, 'inventory/ansible')
        ->assertJsonPath('all.hosts', ['web01'])
        ->assertJsonMissingPath('_meta.hostvars.bastion');

    readApi($this, $this->token, 'servers/bastion')
        ->assertOk()
        ->assertJsonPath('data.ansible_excluded', true);
});

it('leaves out pending, revoked and addressless servers', function () {
    ansibleServer('web01');
    ansibleServer('wait01', ['status' => Server::STATUS_PENDING]);
    ansibleServer('gone01', ['status' => Server::STATUS_REVOKED]);
    ansibleServer('noip01', ['primary_ip' => null]);

    $response = readApi($this, $this->token, 'inventory/ansible')->assertOk();

    expect(array_keys($response->json('_meta.hostvars')))->toBe(['web01'])
        ->and($response->json('all.hosts'))->toBe(['web01']);
});

it('keeps a stale server, flagged and grouped', function () {
    ansibleServer('web01', ['last_seen_at' => now()->subDay(), 'docker_version' => '27.0']);
    ansibleServer('web02');

    readApi($this, $this->token, 'inventory/ansible')
        ->assertJsonPath('_meta.hostvars.web01.stackcard_stale', true)
        ->assertJsonPath('_meta.hostvars.web02.stackcard_stale', false)
        ->assertJsonPath('stale.hosts', ['web01'])
        ->assertJsonPath('docker.hosts', ['web01'])
        ->assertJsonPath('all.hosts', ['web01', 'web02'])
        ->assertJsonPath('all.children', ['docker', 'stale']);
});

it('refuses a request without a token', function () {
    app('auth')->forgetGuards();

    $this->getJson('/api/v1/inventory/ansible')->assertUnauthorized();
});

it('refuses an address that is not allowed', function () {
    readApi($this, $this->token, 'inventory/ansible', '198.51.100.9')->assertForbidden();
});
