<?php

use App\Actions\CreateEnrollmentToken;
use App\Models\EnrollmentToken;
use App\Models\Server;

function enrollPayload(string $token, array $overrides = []): array
{
    return array_merge(['enrollment_token' => $token, 'machine_id' => 'abc123', 'hostname' => 'web01'], $overrides);
}

it('stores only the hash of a new enrollment token, valid for 24 hours', function () {
    $server = Server::create(['name' => 'web01']);
    $plain = app(CreateEnrollmentToken::class)($server);

    $stored = $server->enrollmentTokens()->sole();
    expect(strlen($plain))->toBeGreaterThanOrEqual(40)
        ->and($stored->token_hash)->toBe(hash('sha256', $plain))
        ->and($stored->token_hash)->not->toContain($plain)
        ->and($stored->expires_at->between(now()->addHours(23), now()->addHours(25)))->toBeTrue();
});

it('replaces any unused enrollment token of the server', function () {
    $server = Server::create(['name' => 'web01']);
    app(CreateEnrollmentToken::class)($server);
    app(CreateEnrollmentToken::class)($server);

    expect($server->enrollmentTokens()->count())->toBe(1);
});

it('enrolls a server and returns an agent token once', function () {
    $server = Server::create(['name' => 'web01']);
    $plain = app(CreateEnrollmentToken::class)($server);

    $response = $this->postJson('/api/v1/enroll', enrollPayload($plain));

    $response->assertCreated()->assertJsonPath('server_id', $server->id);
    $server->refresh();
    expect($server->status)->toBe(Server::STATUS_ACTIVE)
        ->and($server->machine_id)->toBe('abc123')
        ->and($server->hostname)->toBe('web01')
        ->and($server->enrollmentTokens()->sole()->used_at)->not->toBeNull()
        ->and($server->tokens()->sole()->can(Server::AGENT_ABILITY))->toBeTrue()
        ->and($response->json('token'))->toContain('|');
});

it('re-enrolling revokes the previous agent token', function () {
    $server = Server::create(['name' => 'web01']);
    $this->postJson('/api/v1/enroll', enrollPayload(app(CreateEnrollmentToken::class)($server)))->assertCreated();
    $this->postJson('/api/v1/enroll', enrollPayload(app(CreateEnrollmentToken::class)($server)))->assertCreated();

    expect($server->tokens()->count())->toBe(1);
});

it('refuses a reused enrollment token', function () {
    $plain = app(CreateEnrollmentToken::class)(Server::create(['name' => 'web01']));
    $this->postJson('/api/v1/enroll', enrollPayload($plain))->assertCreated();

    $this->postJson('/api/v1/enroll', enrollPayload($plain))->assertStatus(410);
});

it('refuses an expired enrollment token', function () {
    $server = Server::create(['name' => 'web01']);
    $plain = app(CreateEnrollmentToken::class)($server);
    $this->travel(EnrollmentToken::VALID_HOURS + 1)->hours();

    $this->postJson('/api/v1/enroll', enrollPayload($plain))->assertStatus(410);
    expect($server->tokens()->count())->toBe(0);
});

it('refuses an unknown enrollment token', function () {
    $this->postJson('/api/v1/enroll', enrollPayload('nope'))->assertStatus(410);
});

it('refuses a token issued before the server was revoked', function () {
    $server = Server::create(['name' => 'web01']);
    $plain = app(CreateEnrollmentToken::class)($server);
    $server->update(['status' => Server::STATUS_REVOKED]);

    $this->postJson('/api/v1/enroll', enrollPayload($plain))->assertStatus(410);
});

it('refuses a machine already enrolled as another server', function () {
    Server::create(['name' => 'old', 'machine_id' => 'abc123', 'status' => Server::STATUS_ACTIVE]);
    $plain = app(CreateEnrollmentToken::class)(Server::create(['name' => 'web01']));

    $this->postJson('/api/v1/enroll', enrollPayload($plain))
        ->assertStatus(409)
        ->assertJsonPath('message', fn ($message) => str_contains($message, 'old'));
});

it('validates the enrollment payload', function () {
    $this->postJson('/api/v1/enroll', [])->assertUnprocessable()
        ->assertJsonValidationErrors(['enrollment_token', 'hostname']);
});

it('rate-limits enrollment attempts', function () {
    foreach (range(1, 10) as $_) {
        $this->postJson('/api/v1/enroll', enrollPayload('guess'))->assertStatus(410);
    }
    $this->postJson('/api/v1/enroll', enrollPayload('guess'))->assertStatus(429);
});
