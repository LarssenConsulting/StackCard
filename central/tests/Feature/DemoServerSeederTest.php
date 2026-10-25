<?php

use App\Models\Server;
use Database\Seeders\DemoServerSeeder;

it('creates a fake server with PHP sites and Node apps, and refreshes it when run again', function () {
    $this->seed(DemoServerSeeder::class);
    $this->seed(DemoServerSeeder::class);

    $server = Server::where('name', DemoServerSeeder::NAME)->sole();
    expect($server->status)->toBe(Server::STATUS_ACTIVE)
        ->and($server->sites()->whereNotNull('php_version')->distinct()->pluck('php_version')->count())->toBeGreaterThan(3)
        ->and($server->nodeApps()->count())->toBe(5)
        ->and($server->nodeApps()->whereDoesntHave('sites')->sole()->name)->toBe('mailer-worker')
        ->and($server->sites()->where('type', 'node')->whereNull('node_app_id')->count())->toBe(0)
        ->and($server->snapshots()->count())->toBe(2);
});
