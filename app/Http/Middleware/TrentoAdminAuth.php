<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrentoAdminAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $expectedToken = env('TRENTO_ADMIN_TOKEN');

        if (empty($expectedToken)) {
            return response()->json(['error' => 'Admin endpoint not configured'], 401);
        }

        $authHeader = $request->header('Authorization');

        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $providedToken = substr($authHeader, 7);

        if (!hash_equals($expectedToken, $providedToken)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
