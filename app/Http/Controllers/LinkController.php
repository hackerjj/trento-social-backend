<?php

namespace App\Http\Controllers;

use App\Models\TrentoClick;
use App\Models\TrentoLink;
use App\Support\UserAgentParser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class LinkController extends Controller
{
    private const CODE_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    private const CODE_LENGTH = 7;
    private const MAX_CODE_RETRIES = 5;

    /**
     * Crea un enlace corto (admin, FR-4.1). Valida destino http/https y genera
     * un code base62 de 7 chars con reintento ante colision.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'destination' => 'required|url|starts_with:http://,https://|max:2000',
            'label'       => 'nullable|string|max:160',
        ]);

        $code = $this->generateUniqueCode();

        $link = TrentoLink::create([
            'code'        => $code,
            'destination' => $validated['destination'],
            'label'       => $validated['label'] ?? null,
            'active'      => true,
        ]);

        $shortUrl = rtrim(config('app.url'), '/') . '/t/' . $code;

        return response()->json([
            'success'   => true,
            'code'      => $link->code,
            'short_url' => $shortUrl,
        ]);
    }

    /**
     * Lista enlaces con su conteo rapido de clics desde Redis (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $links = TrentoLink::orderBy('created_at', 'desc')->get();

        $data = $links->map(function (TrentoLink $link) {
            $clicks = 0;
            try {
                $clicks = (int) (Redis::connection('counters')
                    ->get('trento:link:' . $link->code . ':clicks') ?? 0);
            } catch (\Throwable $e) {
                Log::warning('No se pudo leer el contador de clics: ' . $e->getMessage());
            }

            return [
                'code'        => $link->code,
                'destination' => $link->destination,
                'label'       => $link->label,
                'active'      => (bool) $link->active,
                'clicks'      => $clicks,
                'created_at'  => $link->created_at ? (string) $link->created_at : null,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }

    /**
     * Redirect publico (ruta suelta sin grupo web, 302). Nunca espera a la geo:
     * incrementa contadores baratos, difiere geo+UA+escritura de clic con terminating().
     */
    public function redirect(string $code, Request $request): Response|RedirectResponse
    {
        $link = TrentoLink::where('code', $code)->where('active', true)->first();

        if (!$link) {
            return response('Enlace no encontrado', 404);
        }

        $ip = $request->ip();
        $userAgent = $request->userAgent();
        $referrer = $request->header('Referer');
        $ts = Carbon::now();
        $today = $ts->format('Y-m-d');

        // Incrementos baratos y sincronos (microsegundos). No bloquean el 302.
        try {
            $counters = Redis::connection('counters');
            $counters->incr('trento:link:' . $code . ':clicks');
            $dailyKey = 'trento:link:' . $code . ':daily:' . $today;
            $newDaily = $counters->incr($dailyKey);
            if ((int) $newDaily === 1) {
                // 400 dias de retencion de la serie diaria.
                $counters->expire($dailyKey, 400 * 86400);
            }
            if ($ip !== null) {
                $counters->pfadd('trento:link:' . $code . ':uniq', [$ip]);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudieron incrementar contadores del redirect: ' . $e->getMessage());
        }

        // Trabajo caro diferido: corre tras enviar la respuesta (mismo proceso con artisan serve).
        app()->terminating(function () use ($code, $ip, $userAgent, $referrer, $ts) {
            $this->recordClick($code, $ip, $userAgent, $referrer, $ts);
        });

        // En vez de un 302 (que cambiaria la URL a la de destino), servimos una pagina
        // que carga el destino dentro de un iframe a pantalla completa. Asi la barra de
        // direcciones se queda en el enlace corto /t/<code> y nunca se revela la URL larga.
        // El destino siempre es el propio sitio de Trento Social, por lo que no hay
        // bloqueo de iframe (X-Frame-Options) que lo impida.
        $destino = htmlspecialchars($link->destination, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="referrer" content="no-referrer">
<title>Trento Social</title>
<link rel="icon" type="image/png" sizes="32x32" href="https://hackerjj.github.io/trento-social/favicon-32.png">
<style>
  html,body{margin:0;padding:0;height:100%;background:#fff;overflow:hidden}
  iframe{border:0;width:100%;height:100%;display:block}
</style>
</head>
<body>
<iframe src="{$destino}" title="Trento Social" allow="clipboard-write"></iframe>
</body>
</html>
HTML;

        return response($html, 200)
            ->header('Content-Type', 'text/html; charset=UTF-8')
            ->header('X-Frame-Options', 'SAMEORIGIN');
    }

    /**
     * Analiticas de un enlace (admin, FR-4.3). Mongo es la fuente de verdad.
     */
    public function stats(string $code, Request $request): JsonResponse
    {
        $link = TrentoLink::where('code', $code)->first();

        if (!$link) {
            return response()->json([
                'success' => false,
                'message' => 'Enlace no encontrado',
            ], 404);
        }

        $clicks = TrentoClick::where('code', $code)->get();

        $total = $clicks->count();
        $unique = $clicks->pluck('ip')->filter()->unique()->count();

        $byCountry = [];
        $byDevice = [];
        $byBrowser = [];
        $byReferrer = [];
        $byDay = [];

        foreach ($clicks as $click) {
            $geo = is_array($click->geo) ? $click->geo : [];
            $country = $geo['country'] ?? 'desconocido';
            $byCountry[$country] = ($byCountry[$country] ?? 0) + 1;

            $device = $click->device ?? 'desconocido';
            $byDevice[$device] = ($byDevice[$device] ?? 0) + 1;

            $browser = $click->browser ?? 'desconocido';
            $byBrowser[$browser] = ($byBrowser[$browser] ?? 0) + 1;

            $ref = $click->referrer ?: 'directo';
            $byReferrer[$ref] = ($byReferrer[$ref] ?? 0) + 1;

            $day = $click->ts ? Carbon::parse($click->ts)->format('Y-m-d') : 'desconocido';
            $byDay[$day] = ($byDay[$day] ?? 0) + 1;
        }

        ksort($byDay);

        return response()->json([
            'success'     => true,
            'code'        => $code,
            'destination' => $link->destination,
            'label'       => $link->label,
            'total'       => $total,
            'unique'      => $unique,
            'by_country'  => $byCountry,
            'by_device'   => $byDevice,
            'by_browser'  => $byBrowser,
            'by_referrer' => $byReferrer,
            'by_day'      => $byDay,
        ]);
    }

    /**
     * Genera un code base62 unico de 7 chars, reintentando ante colision.
     */
    private function generateUniqueCode(): string
    {
        $alphabetMax = strlen(self::CODE_ALPHABET) - 1;

        for ($attempt = 0; $attempt < self::MAX_CODE_RETRIES; $attempt++) {
            $code = '';
            for ($i = 0; $i < self::CODE_LENGTH; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, $alphabetMax)];
            }

            if (!TrentoLink::where('code', $code)->exists()) {
                return $code;
            }
        }

        // Extremadamente improbable: 5 colisiones seguidas en un espacio de ~3.5e12.
        abort(500, 'No se pudo generar un código único.');
    }

    /**
     * Trabajo diferido: geolocaliza la IP (con cache), parsea el UA y escribe el clic.
     * Cualquier fallo se loguea pero no afecta el redirect ya enviado.
     */
    private function recordClick(string $code, ?string $ip, ?string $userAgent, ?string $referrer, Carbon $ts): void
    {
        try {
            $geo = $this->resolveGeo($ip);
            $parsed = UserAgentParser::parse($userAgent);

            TrentoClick::create([
                'code'       => $code,
                'ts'         => $ts,
                'ip'         => $ip,
                'geo'        => $geo,
                'device'     => $parsed['device'],
                'browser'    => $parsed['browser'],
                'os'         => $parsed['os'],
                'referrer'   => $referrer,
                'user_agent' => $userAgent,
            ]);

            if (!empty($geo['country'])) {
                try {
                    Redis::connection('counters')
                        ->incr('trento:link:' . $code . ':geo:' . $geo['country']);
                } catch (\Throwable $e) {
                    Log::warning('No se pudo incrementar el contador por pais: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Fallo el registro diferido del clic: ' . $e->getMessage());
        }
    }

    /**
     * Geolocalizacion por IP, best-effort, con cache de 24h y timeout corto.
     * Devuelve ['country'=>?,'city'=>?]; ambos null si falla o esta desactivada.
     *
     * @return array{country: ?string, city: ?string}
     */
    private function resolveGeo(?string $ip): array
    {
        $empty = ['country' => null, 'city' => null];

        $provider = env('GEO_PROVIDER', 'ipwhois');

        if (!$ip || $provider === 'none') {
            return $empty;
        }

        // No tiene sentido geolocalizar IPs privadas/reservadas.
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $empty;
        }

        $cacheKey = 'trento:geo:' . $ip;

        try {
            $cached = Redis::connection('counters')->get($cacheKey);
            if ($cached !== null) {
                $decoded = json_decode($cached, true);
                if (is_array($decoded)) {
                    return [
                        'country' => $decoded['country'] ?? null,
                        'city'    => $decoded['city'] ?? null,
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo leer la cache de geo: ' . $e->getMessage());
        }

        $timeoutMs = (int) env('GEO_TIMEOUT_MS', 800);
        $timeout = max(0.1, $timeoutMs / 1000);

        $geo = $empty;

        try {
            if ($provider === 'ip-api') {
                $resp = Http::timeout($timeout)
                    ->get('http://ip-api.com/json/' . $ip, ['fields' => 'status,country,countryCode,city']);
                if ($resp->ok() && ($resp->json('status') === 'success')) {
                    $geo = [
                        'country' => $resp->json('countryCode'),
                        'city'    => $resp->json('city'),
                    ];
                }
            } else {
                // ipwhois (por defecto): HTTPS, gratuito, sin API key.
                $resp = Http::timeout($timeout)->get('https://ipwho.is/' . $ip);
                if ($resp->ok() && ($resp->json('success') === true)) {
                    $geo = [
                        'country' => $resp->json('country_code'),
                        'city'    => $resp->json('city'),
                    ];
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Fallo la geolocalización: ' . $e->getMessage());
            $geo = $empty;
        }

        try {
            Redis::connection('counters')->set($cacheKey, json_encode($geo), 'EX', 86400);
        } catch (\Throwable $e) {
            Log::warning('No se pudo cachear la geo: ' . $e->getMessage());
        }

        return $geo;
    }
}
