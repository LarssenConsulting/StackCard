<?php

use App\Models\Database;
use App\Models\DbGrant;
use App\Models\DbUser;
use App\Models\EnrollmentToken;
use App\Models\Server;
use App\Models\ServerIp;
use App\Models\Site;
use App\Models\SiteDbRef;
use App\Models\Snapshot;

function seedServer(): Server
{
    $server = Server::create(['name' => 'web01', 'status' => Server::STATUS_ACTIVE]);
    $server->ips()->create(['interface' => 'eth0', 'address' => '10.0.0.11', 'prefix' => 24]);
    $site = $server->sites()->create([
        'server_name' => 'shop.example.com',
        'aliases' => ['www.shop.example.com'],
        'port' => 443,
        'https' => true,
        'type' => 'php-fpm',
        'partial' => false,
    ]);
    $database = $server->databases()->create(['name' => 'shop_prod', 'size_bytes' => 1024]);
    $user = $server->dbUsers()->create(['user' => 'shop', 'host' => 'localhost']);
    $user->grants()->create(['database_id' => $database->id, 'privileges' => ['SELECT'], 'global' => false]);
    $site->dbRefs()->create([
        'db_name' => 'shop_prod', 'db_user' => 'shop', 'db_host' => 'localhost',
        'database_id' => $database->id, 'db_user_id' => $user->id, 'resolution' => SiteDbRef::LOCAL,
    ]);
    $server->snapshots()->create([
        'received_at' => now(), 'schema_version' => 1, 'payload' => ['schema_version' => 1], 'error_count' => 0,
    ]);
    $server->enrollmentTokens()->create(['token_hash' => str_repeat('a', 64), 'expires_at' => now()->addDay()]);

    return $server;
}

it('links the inventory of a server through its relations', function () {
    $server = seedServer();
    $site = $server->sites()->first();
    $ref = $site->dbRefs()->first();

    expect($server->ips)->toHaveCount(1)
        ->and($site->aliases)->toBe(['www.shop.example.com'])
        ->and($site->https)->toBeTrue()
        ->and($ref->database->name)->toBe('shop_prod')
        ->and($ref->dbUser->user)->toBe('shop')
        ->and($ref->database->server->is($server))->toBeTrue()
        ->and($server->dbUsers()->first()->grants()->first()->privileges)->toBe(['SELECT'])
        ->and($server->databases()->first()->siteDbRefs)->toHaveCount(1)
        ->and($server->snapshots()->first()->payload)->toBe(['schema_version' => 1]);
});

it('deletes the whole inventory with its server', function () {
    seedServer()->delete();

    foreach ([Site::class, SiteDbRef::class, Database::class, DbUser::class, DbGrant::class,
              ServerIp::class, Snapshot::class, EnrollmentToken::class] as $model) {
        expect($model::count())->toBe(0, $model);
    }
});

it('keeps a site reference when the database it pointed to disappears', function () {
    $server = seedServer();
    $server->databases()->delete();

    $ref = SiteDbRef::first();
    expect($ref)->not->toBeNull()
        ->and($ref->database_id)->toBeNull();
});

it('lets a server own Sanctum tokens', function () {
    $server = seedServer();
    $token = $server->createToken('agent', ['inventory:write']);

    expect($token->plainTextToken)->toContain('|')
        ->and($server->tokens()->first()->can('inventory:write'))->toBeTrue();
});
