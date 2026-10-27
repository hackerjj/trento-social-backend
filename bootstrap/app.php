<?php

use App\Http\Controllers\LinkController;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        health: '/up',
        then: function () {
            // Ruta suelta del acortador: SIN grupo web (sin StartSession, sin CSRF,
            // sin ShareErrorsFromSession). El 302 no debe tocar sesion ni CSRF.
            Route::get('/t/{code}', [LinkController::class, 'redirect']);
        },
    )
    ->booted(function () {
        // Fallback cache driver to 'file' if Redis is unavailable
        if (config('cache.default') === 'redis') {
            try {
                \Illuminate\Support\Facades\Cache::store('redis')->get('_health_check');
            } catch (\Throwable $e) {
                config(['cache.default' => 'file']);
                Log::warning('Redis unavailable, falling back to file cache: ' . $e->getMessage());
            }
        }
    })
    ->withMiddleware(function (Middleware $middleware) {
        // Render pone Cloudflare + su balanceador interno delante de la app. Confiamos
        // SOLO en esos saltos (loopback, redes privadas y rangos de Cloudflare), no en '*':
        // con '*' $request->ip() devolvia el 10.x interno de Render. Asi Symfony recorre
        // X-Forwarded-For de derecha a izquierda y toma la primera IP no confiable, y un
        // X-Forwarded-For inventado por el cliente (a la izquierda) queda ignorado.
        // Detalle completo en App\Http\Middleware\ResolveClientIp.
        $middleware->trustProxies(at: \App\Http\Middleware\ResolveClientIp::TRUSTED_PROXIES, headers:
            \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
            \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO
        );
        // Global (corre antes del throttle de rutas): prefiere CF-Connecting-IP cuando
        // el request viene de un salto confiable. Aplica a /t/{code}, /api/* y rate limits.
        $middleware->append(\App\Http\Middleware\ResolveClientIp::class);
        $middleware->api(prepend: [
            \App\Http\Middleware\CorrelationId::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \Illuminate\Http\Middleware\HandleCors::class,
        ]);

        $middleware->alias([
            'trento.admin' => \App\Http\Middleware\TrentoAdminAuth::class,
            'throttle' => \Illuminate\Routing\Middleware\ThrottleRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // 404 - Model not found: generic message, no model class name leakage
        $exceptions->renderable(function (ModelNotFoundException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found',
                ], 404);
            }
        });

        // 404 - Route not found: generic message
        $exceptions->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Endpoint not found',
                ], 404);
            }
        });

        // 422 - Validation errors: return field errors only, no stack traces
        $exceptions->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation failed',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // 500 - All other exceptions: sanitized in production, full log server-side
        $exceptions->renderable(function (\Throwable $e, $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                // Get correlation ID from the request (set by CorrelationId middleware)
                $correlationId = $request->header('X-Correlation-ID') ?: (string) \Illuminate\Support\Str::uuid();

                // Always log full exception context server-side for correlation lookup
                Log::error('Unhandled exception', [
                    'correlation_id' => $correlationId,
                    'exception' => get_class($e),
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'ip' => $request->ip(),
                ]);

                // In production: return generic message + correlation ID only
                // Never include DB connection strings, query details, or internal service names
                if (app()->environment('production')) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Internal server error',
                        'correlation_id' => $correlationId,
                    ], 500);
                }

                // In non-production: include sanitized error info for debugging
                // Still strip DB connection strings and sensitive details
                $sanitizedMessage = $e->getMessage();
                // Remove potential DB connection strings (mongodb://, redis://, mysql://)
                $sanitizedMessage = preg_replace(
                    '/(?:mongodb|redis|mysql|pgsql):\/\/[^\s"\']+/',
                    '[REDACTED_CONNECTION_STRING]',
                    $sanitizedMessage
                );

                return response()->json([
                    'success' => false,
                    'message' => $sanitizedMessage,
                    'correlation_id' => $correlationId,
                    'trace' => $e->getTraceAsString(),
                ], 500);
            }
        });
    })
    ->create();
