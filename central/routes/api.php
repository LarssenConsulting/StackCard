<?php

use App\Http\Controllers\Api\AnsibleInventoryController;
use App\Http\Controllers\Api\DomainReadController;
use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ServerReadController;
use App\Http\Middleware\EnsureApiClientIsAllowed;
use App\Http\Middleware\LimitRequestBody;
use App\Models\ApiClient;
use App\Models\Server;
use App\Models\ServerSection;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:enroll');

    Route::post('inventory', InventoryController::class)->middleware([
        LimitRequestBody::class,
        'auth:sanctum',
        'abilities:'.Server::AGENT_ABILITY,
        'throttle:inventory',
    ]);

    // Read-only, for external applications.
    Route::middleware([
        'throttle:read-api',
        'auth:sanctum',
        'abilities:'.ApiClient::READ_ABILITY,
        EnsureApiClientIsAllowed::class,
    ])->controller(ServerReadController::class)->group(function () {
        Route::get('servers', 'index');
        Route::get('servers/{server}', 'show')->where('server', '[a-z0-9-]+');
        Route::get('servers/{server}/sections/{section}', 'section')
            ->where('server', '[a-z0-9-]+')
            ->whereIn('section', ServerSection::NAMES);
        Route::get('domains', [DomainReadController::class, 'index']);
        Route::get('inventory/ansible', AnsibleInventoryController::class);
    });
});
