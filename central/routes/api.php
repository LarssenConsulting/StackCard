<?php

use App\Http\Controllers\Api\EnrollController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Middleware\LimitRequestBody;
use App\Models\Server;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:enroll');

    Route::post('inventory', InventoryController::class)->middleware([
        LimitRequestBody::class,
        'auth:sanctum',
        'abilities:'.Server::AGENT_ABILITY,
        'throttle:inventory',
    ]);
});
