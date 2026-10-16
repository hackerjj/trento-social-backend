<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Alta publica (POST /api/trento/registro): 10 solicitudes por minuto por IP.
        RateLimiter::for('trento-public', function (Request $request) {
            return Limit::perMinute(10)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta más tarde.',
                    ], 429, $headers);
                });
        });

        // Endpoints admin: 60 solicitudes por minuto por IP.
        RateLimiter::for('trento-admin', function (Request $request) {
            return Limit::perMinute(60)
                ->by($request->ip())
                ->response(function (Request $request, array $headers) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Demasiadas solicitudes. Intenta más tarde.',
                    ], 429, $headers);
                });
        });
    }
}
