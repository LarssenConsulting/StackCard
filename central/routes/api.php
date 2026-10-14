<?php

use App\Http\Controllers\Api\EnrollController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('enroll', EnrollController::class)->middleware('throttle:enroll');
});
