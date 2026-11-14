<?php

use App\Http\Controllers\WikiController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Public wiki, no authentication: generic content only (see WikiTest).
Route::get('/wiki', [WikiController::class, 'index'])->name('wiki');
Route::get('/wiki/{page}', [WikiController::class, 'show'])
    ->where('page', '[a-z0-9-]+')
    ->name('wiki.page');
