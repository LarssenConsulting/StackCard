<?php

use App\Models\Server;
use App\Support\ServerSlug;

it('builds a slug from a name', function () {
    expect(ServerSlug::from('Client X – prod (OVH)'))->toBe('client-x-prod-ovh')
        ->and(ServerSlug::from('123'))->toBe('srv-123')
        ->and(ServerSlug::from('——'))->toBe('srv')
        ->and(strlen(ServerSlug::from(str_repeat('a', 100))))->toBe(64);
});

it('validates the slug format', function () {
    expect(ServerSlug::isValid('123'))->toBeFalse()
        ->and(ServerSlug::isValid('web-01'))->toBeTrue()
        ->and(ServerSlug::isValid('Web01'))->toBeFalse()
        ->and(ServerSlug::isValid('web--01'))->toBeFalse()
        ->and(ServerSlug::isValid(str_repeat('a', 65)))->toBeFalse();
});

it('suffixes a slug already taken', function () {
    Server::create(['name' => 'web01']);

    expect(ServerSlug::unique('web01'))->toBe('web01-2');

    Server::create(['name' => 'web01']);
    expect(ServerSlug::unique('web01'))->toBe('web01-3');
    $long = str_repeat('a', 64);
    Server::create(['name' => $long]);
    expect(strlen(ServerSlug::unique($long)))->toBeLessThanOrEqual(64)->and(ServerSlug::unique($long))->not->toBe($long);
});

it('gives every new server a slug', function () {
    $a = Server::create(['name' => 'Web 01']);
    $b = Server::create(['name' => 'Web 01']);

    expect($a->slug)->toBe('web-01')->and($b->slug)->toBe('web-01-2')->and($a->isSlugLocked())->toBeFalse();
});

it('prefers the manual address over the primary ip', function () {
    $server = Server::create(['name' => 'a', 'primary_ip' => '10.0.0.1']);
    expect($server->effectiveAddress())->toBe('10.0.0.1');

    $server->connect_address = 'web.example.org';
    expect($server->effectiveAddress())->toBe('web.example.org');
});

it('finds a server by id or by slug', function () {
    $server = Server::create(['name' => 'Web 01']);

    expect(Server::findByKey((string) $server->id)->is($server))->toBeTrue()
        ->and(Server::findByKey('web-01')->is($server))->toBeTrue();
    expect(fn () => Server::findByKey('nope'))->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

it('locks the slug at the first inventory only', function () {
    [$server, $token] = enrolledServer();
    $this->withToken($token)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();
    expect($server->refresh()->slug_locked_at)->not->toBeNull()->and($server->isSlugLocked())->toBeTrue();

    [$seen, $token2] = enrolledServer('web02', 'def456');
    $seen->forceFill(['last_seen_at' => now()->subDay()])->save();
    app('auth')->forgetGuards();
    $this->withToken($token2)->postJson('/api/v1/inventory', snapshotFixture())->assertOk();
    expect($seen->refresh()->slug_locked_at)->toBeNull();
});

it('never lets a locked slug change, whatever the code path', function () {
    $server = Server::create(['name' => 'keep']);
    $server->forceFill(['slug_locked_at' => now()])->save();

    expect(fn () => $server->forceFill(['slug' => 'other'])->save())->toThrow(LogicException::class);
    expect(fn () => Server::whereKey($server->id)->first()->update(['slug' => 'other']))->toThrow(LogicException::class);
    expect($server->fresh()->slug)->toBe('keep');

    $free = Server::create(['name' => 'free']);
    $free->update(['slug' => 'free-renamed']);
    expect($free->fresh()->slug)->toBe('free-renamed');
});
