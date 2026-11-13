<?php

use App\Http\Controllers\AifResultadoController;
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

// ---- Curso AIF-C01 (sitios de estudio de Jair y Gaby) ----
Route::post('/aif/resultado', [AifResultadoController::class, 'store'])
    ->middleware('throttle:aif-public');
Route::get('/aif/resultados', [AifResultadoController::class, 'index'])
    ->middleware(['trento.admin', 'throttle:trento-admin']);
