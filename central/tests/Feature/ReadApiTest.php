<?php

use App\Models\ApiClient;
use App\Models\Server;
use App\Models\ServerSection;
use Illuminate\Support\Facades\Log;

const ALLOWED_IP = '203.0.113.10';

/** A client allowed from ALLOWED_IP and the plain token its website would hold. */
function readClient(array $ips = [ALLOWED_IP], string $name = 'site-cve'): array
{
    $client = ApiClient::create(['name' => $name, 'allowed_ips' => $ips]);

    return [$client, $client->issueToken()];
}

function readApi($test, string $token, string $path, string $ip = ALLOWED_IP)
{
    app('auth')->forgetGuards();

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->withToken($token)
        ->getJson("/api/v1/{$path}");
}

function storedSection(Server $server, string $name, array $attributes = []): ServerSection
{
    return $server->sections()->create(array_merge([
        'section' => $name, 'collected_at' => '2026-11-01 08:00:00', 'sha256' => str_repeat('a', 64),
        'source_errors' => [], 'data' => ['k' => 'v'], 'first_seen_at' => now(), 'last_seen_at' => now(),
    ], $attributes));
}

beforeEach(function () {
    [$this->server, $this->agentToken] = enrolledServer();
    $this->server->update(['hostname' => 'web01', 'os' => 'Ubuntu 24.04', 'notes' => 'secret note', 'last_seen_at' => now()]);
    [$this->client, $this->token] = readClient();
});

it('lists the servers with their freshness', function () {
    readApi($this, $this->token, 'servers')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('data.0.name', 'web01')
        ->assertJsonPath('data.0.os', 'Ubuntu 24.04')
        ->assertJsonPath('data.0.stale', false);
});

it('never exposes notes or the machine id', function () {
    $list = readApi($this, $this->token, 'servers')->getContent();
    $detail = readApi($this, $this->token, "servers/{$this->server->id}")->getContent();

    foreach ([$list, $detail] as $body) {
        expect($body)->not->toContain('secret note')->not->toContain('abc123')->not->toContain('machine_id');
    }
});

it('details a server from a real inventory, with the state of each section but not its data', function () {
    app('auth')->forgetGuards();
    $this->withToken($this->agentToken)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();

    $response = readApi($this, $this->token, "servers/{$this->server->id}")->assertOk();

    expect($response->json('data.sites'))->not->toBeEmpty()
        ->and($response->json('data.sites.0'))->toHaveKeys(['server_name', 'php_version', 'document_root', 'db_refs'])
        ->and($response->json('data.ips'))->not->toBeEmpty()
        ->and($response->json('data.firewall'))->toHaveKeys(['status', 'default_incoming', 'rules'])
        ->and($response->json('data.db_users.0'))->toHaveKeys(['user', 'host', 'grants'])
        ->and($response->json('data.sections.packages'))->toHaveKeys(['collected_at', 'last_seen_at', 'sha256', 'error', 'source_errors'])
        ->and($response->json('data.sections.packages'))->not->toHaveKey('data');
});

it('names the sections never received', function () {
    storedSection($this->server, 'kernel');

    $response = readApi($this, $this->token, "servers/{$this->server->id}")->assertOk();

    expect($response->json('data.sections_never_received'))->toContain('packages')->not->toContain('kernel');
});

it('returns the newest state of one section with its data', function () {
    storedSection($this->server, 'packages', ['data' => [['package' => 'old']]]);
    storedSection($this->server, 'packages', ['data' => [['package' => 'openssl', 'version' => '3.0.13-0ubuntu3.5']]]);

    readApi($this, $this->token, "servers/{$this->server->id}/sections/packages")
        ->assertOk()
        ->assertJsonPath('data.section', 'packages')
        ->assertJsonPath('data.server_id', $this->server->id)
        ->assertJsonPath('data.data.0.version', '3.0.13-0ubuntu3.5')
        ->assertJsonPath('data.error', null);
});

it('tells an unread section from an empty one', function () {
    storedSection($this->server, 'integrity', ['error' => 'timeout', 'data' => null, 'sha256' => null]);
    storedSection($this->server, 'code_patterns', ['data' => []]);

    readApi($this, $this->token, "servers/{$this->server->id}/sections/integrity")
        ->assertOk()->assertJsonPath('data.error', 'timeout')->assertJsonPath('data.data', null);
    readApi($this, $this->token, "servers/{$this->server->id}/sections/code_patterns")
        ->assertOk()->assertJsonPath('data.error', null)->assertJsonPath('data.data', []);
});

it('answers 404 for an unknown server, an unknown section or one never received', function () {
    readApi($this, $this->token, 'servers/99999')->assertNotFound();
    readApi($this, $this->token, "servers/{$this->server->id}/sections/nope")->assertNotFound();
    readApi($this, $this->token, "servers/{$this->server->id}/sections/packages")->assertNotFound();
});

it('refuses an address that is not allowed, and logs it', function () {
    Log::spy();

    readApi($this, $this->token, 'servers', '198.51.100.7')->assertForbidden()->assertJsonMissing(['name' => 'web01']);

    Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message, $context) => $context['client'] === 'site-cve'
        && $context['ip'] === '198.51.100.7');
});

it('accepts a CIDR range', function () {
    [, $token] = readClient(['203.0.113.0/24'], 'range');

    readApi($this, $token, 'servers', '203.0.113.200')->assertOk();
    readApi($this, $token, 'servers', '203.0.114.1')->assertForbidden();
});

it('accepts an IPv6 address', function () {
    [, $token] = readClient(['2001:db8::1'], 'v6');

    readApi($this, $token, 'servers', '2001:db8::1')->assertOk();
    readApi($this, $token, 'servers', '2001:db8::2')->assertForbidden();
});

it('refuses everything when no address is allowed', function () {
    [, $token] = readClient([], 'empty');

    readApi($this, $token, 'servers')->assertForbidden();
});

it('cannot be fooled by a forwarded address', function () {
    app('auth')->forgetGuards();

    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])
        ->withHeaders(['X-Forwarded-For' => ALLOWED_IP, 'X-Real-IP' => ALLOWED_IP, 'Client-IP' => ALLOWED_IP])
        ->withToken($this->token)
        ->getJson('/api/v1/servers')
        ->assertForbidden();
});

it('refuses a request without a token, or with a wrong one', function () {
    app('auth')->forgetGuards();
    $this->withServerVariables(['REMOTE_ADDR' => ALLOWED_IP])->getJson('/api/v1/servers')->assertUnauthorized();

    readApi($this, 'nope', 'servers')->assertUnauthorized();
});

it('refuses the token of an agent: a compromised server cannot read the fleet', function () {
    readApi($this, $this->agentToken, 'servers')->assertForbidden();
    readApi($this, $this->agentToken, "servers/{$this->server->id}")->assertForbidden();
});

it('cannot send an inventory with a read token', function () {
    app('auth')->forgetGuards();

    $this->withServerVariables(['REMOTE_ADDR' => ALLOWED_IP])
        ->withToken($this->token)
        ->postJson('/api/v1/inventory', snapshotFixture())
        ->assertForbidden();
});

it('refuses an expired token', function () {
    $this->client->tokens()->update(['expires_at' => now()->subMinute()]);

    readApi($this, $this->token, 'servers')->assertUnauthorized();
});

it('invalidates the previous token when a new one is issued', function () {
    $new = $this->client->issueToken();

    readApi($this, $this->token, 'servers')->assertUnauthorized();
    readApi($this, $new, 'servers')->assertOk();
});

it('revokes the token when the client is deleted', function () {
    $this->client->delete();

    readApi($this, $this->token, 'servers')->assertUnauthorized();
    expect(Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', ApiClient::class)->count())->toBe(0);
});

it('only offers reading', function () {
    app('auth')->forgetGuards();

    $this->withServerVariables(['REMOTE_ADDR' => ALLOWED_IP])->withToken($this->token)
        ->deleteJson("/api/v1/servers/{$this->server->id}")->assertMethodNotAllowed();
});

it('limits the request rate per address', function () {
    foreach (range(1, 120) as $ignored) {
        readApi($this, $this->token, 'servers')->assertOk();
    }

    readApi($this, $this->token, 'servers')->assertTooManyRequests();
    readApi($this, $this->token, 'servers', '198.51.100.7')->assertForbidden();
});
