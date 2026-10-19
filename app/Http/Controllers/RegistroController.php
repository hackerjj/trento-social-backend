<?php

namespace App\Http\Controllers;

use App\Models\TrentoRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RegistroController extends Controller
{
    /**
     * Alta publica de un vecino al grupo de WhatsApp (FR-3.2).
     * Rechaza con 422 si el consentimiento no es afirmativo.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'              => 'required|string|min:2|max:120',
            'domicilio'           => 'required|string|min:2|max:160',
            'telefono'            => 'required|string|regex:/^(?:\+?52)?\d{10}$/',
            'tipo_residente'      => 'required|in:propietario,inquilino',
            'personas_vivienda'   => 'required|integer|min:1|max:20',
            'email'               => 'nullable|email|max:160',
            'consentimiento'      => 'required|accepted',
            'aviso_version'       => 'nullable|string|max:40',
            'tiempo_en_pagina_ms' => 'nullable|integer|min:0',
            'referrer'            => 'nullable|string|max:500',
            'screen'              => 'nullable|string|max:20',
            'idioma'              => 'nullable|string|max:20',
            'scroll_depth'        => 'nullable|integer|min:0|max:100',
            'enlace_code'         => 'nullable|string|max:40',
            'zona_horaria'        => 'nullable|string|max:60',
            'ventana'             => 'nullable|string|max:20',
            'plataforma'          => 'nullable|string|max:60',
        ]);

        // Normaliza el telefono a 10 digitos nacionales (quita +52 / 52 inicial).
        $telefonoRaw = $validated['telefono'];
        $telefono = preg_replace('/\D+/', '', $telefonoRaw);
        if (strlen($telefono) === 12 && str_starts_with($telefono, '52')) {
            $telefono = substr($telefono, 2);
        }

        // Evita duplicar el mismo numero de WhatsApp (FR-3.2bis). Se compara el
        // telefono ya normalizado a 10 digitos, sin importar como lo escribio el
        // usuario (con o sin +52).
        $yaExiste = TrentoRegistration::where('telefono', $telefono)->exists();
        if ($yaExiste) {
            return response()->json([
                'success' => false,
                'duplicado' => true,
                'message' => 'Este número ya se encuentra registrado.',
            ], 409);
        }

        // Datos autoritativos del servidor (nunca se confia en el cliente para esto).
        $tracking = [
            'tiempo_en_pagina_ms' => $validated['tiempo_en_pagina_ms'] ?? null,
            'referrer'            => $validated['referrer'] ?? $request->header('Referer'),
            'user_agent'          => $request->userAgent(),
            'screen'              => $validated['screen'] ?? null,
            'idioma'              => $validated['idioma'] ?? null,
            'scroll_depth'        => $validated['scroll_depth'] ?? null,
            'enlace_code'         => $validated['enlace_code'] ?? null,
            'zona_horaria'        => $validated['zona_horaria'] ?? null,
            'ventana'             => $validated['ventana'] ?? null,
            'plataforma'          => $validated['plataforma'] ?? null,
            'ip'                  => $request->ip(),
        ];

        $registration = TrentoRegistration::create([
            'nombre'         => $validated['nombre'],
            'domicilio'      => $validated['domicilio'],
            'telefono'       => $telefono,
            'telefono_raw'   => $telefonoRaw,
            'tipo_residente' => $validated['tipo_residente'],
            'personas_vivienda' => $validated['personas_vivienda'],
            'email'          => $validated['email'] ?? null,
            'consentimiento' => true,
            'consent_ts'     => Carbon::now(),
            'aviso_version'  => $validated['aviso_version'] ?? null,
            'tracking'       => $tracking,
        ]);

        // Contador rapido de volumen (no fatal si Redis cae; Mongo es la fuente de verdad).
        try {
            Redis::connection('counters')->incr('trento:reg:count');
        } catch (\Throwable $e) {
            Log::warning('No se pudo incrementar trento:reg:count: ' . $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'id'      => (string) $registration->getKey(),
        ]);
    }

    /**
     * Lista paginada de registros, orden created_at desc (admin, FR-3.3).
     * Recomputa el total desde Mongo si el contador Redis esta desincronizado.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min($perPage, 200));

        $registros = TrentoRegistration::orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Mongo es la fuente de verdad del total; Redis es solo un atajo.
        $total = TrentoRegistration::count();
        try {
            $cached = Redis::connection('counters')->get('trento:reg:count');
            if ($cached === null || (int) $cached !== $total) {
                Redis::connection('counters')->set('trento:reg:count', $total);
            }
        } catch (\Throwable $e) {
            Log::warning('No se pudo reconciliar trento:reg:count: ' . $e->getMessage());
        }

        return response()->json([
            'success'      => true,
            'total'        => $total,
            'per_page'     => $registros->perPage(),
            'current_page' => $registros->currentPage(),
            'last_page'    => $registros->lastPage(),
            'data'         => $registros->items(),
        ]);
    }

    /**
     * Exporta los registros a CSV con columnas planas (admin, FR-3.3).
     * Aplana tracking.ip / user_agent / referrer / tiempo_en_pagina_ms.
     */
    public function export(Request $request): StreamedResponse
    {
        $columns = [
            'nombre',
            'domicilio',
            'telefono',
            'tipo_residente',
            'personas_vivienda',
            'email',
            'consent_ts',
            'aviso_version',
            'ip',
            'tiempo_en_pagina_ms',
            'referrer',
            'user_agent',
            'created_at',
            'scroll_depth',
            'enlace_code',
            'zona_horaria',
            'ventana',
            'plataforma',
        ];

        $filename = 'trento_registros_' . date('Y-m-d_His') . '.csv';

        $callback = function () use ($columns) {
            $out = fopen('php://output', 'w');
            // BOM para que Excel reconozca UTF-8 (acentos).
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $columns);

            TrentoRegistration::orderBy('created_at', 'desc')
                ->chunk(500, function ($chunk) use ($out) {
                    foreach ($chunk as $reg) {
                        $tracking = is_array($reg->tracking) ? $reg->tracking : [];
                        $consentTs = $reg->consent_ts
                            ? (string) $reg->consent_ts
                            : null;
                        $createdAt = $reg->created_at
                            ? (string) $reg->created_at
                            : null;

                        fputcsv($out, [
                            $reg->nombre,
                            $reg->domicilio,
                            $reg->telefono,
                            $reg->tipo_residente,
                            $reg->personas_vivienda,
                            $reg->email,
                            $consentTs,
                            $reg->aviso_version,
                            $tracking['ip'] ?? null,
                            $tracking['tiempo_en_pagina_ms'] ?? null,
                            $tracking['referrer'] ?? null,
                            $tracking['user_agent'] ?? null,
                            $createdAt,
                            $tracking['scroll_depth'] ?? null,
                            $tracking['enlace_code'] ?? null,
                            $tracking['zona_horaria'] ?? null,
                            $tracking['ventana'] ?? null,
                            $tracking['plataforma'] ?? null,
                        ]);
                    }
                });

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }
}
