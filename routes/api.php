<?php

use App\Http\Controllers\LinkController;
use App\Http\Controllers\RegistroController;
use Illuminate\Support\Facades\Route;

// ---- Publicas ----
Route::post('/trento/registro', [RegistroController::class, 'store'])
    ->middleware('throttle:trento-public');

Route::get('/trento/health', fn () => response()->json(['ok' => true]));

// ---- Admin (token estatico estilo metrics.auth) ----
Route::prefix('trento')
    ->middleware(['trento.admin', 'throttle:trento-admin'])
    ->group(function () {
        Route::get('/registros', [RegistroController::class, 'index']);
        Route::get('/registros/export', [RegistroController::class, 'export']);
        Route::post('/links', [LinkController::class, 'store']);
        Route::get('/links', [LinkController::class, 'index']);
        Route::get('/links/{code}/stats', [LinkController::class, 'stats']);
        Route::get('/links/{code}/clicks', [LinkController::class, 'clicks']);
    });
