<?php

namespace App\Http\Controllers;

use App\Models\AifResultado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AifResultadoController extends Controller
{
    /** Tamaño máximo del estado guardado (bytes de JSON). */
    private const MAX_ESTADO_BYTES = 300000;

    /**
     * Guarda un resultado o un avance del curso AIF-C01.
     * Público (lo llama el navegador del alumno), con límite de peticiones.
     */
    public function store(Request $request): JsonResponse
    {
        // El navegador lo manda como text/plain (sendBeacon) para no requerir
        // preflight de CORS desde GitHub Pages; aquí se decodifica el cuerpo.
        if (! $request->isJson()) {
            $cuerpo = json_decode((string) $request->getContent(), true);
            if (is_array($cuerpo)) {
                $request->merge($cuerpo);
            }
        }

        $validated = $request->validate([
            'usuario'    => ['required', Rule::in(AifResultado::USUARIOS)],
            'modulo'     => ['required', Rule::in(AifResultado::MODULOS)],
            'evento'     => ['required', Rule::in(AifResultado::EVENTOS)],
            'puntaje'    => 'nullable|integer|min:0|max:1000',
            'total'      => 'nullable|integer|min:0|max:1000',
            'tiempo_seg' => 'nullable|integer|min:0|max:86400',
            'estado'     => 'nullable|array',
            'cliente_id' => 'nullable|string|max:60',
        ]);

        $estado = $validated['estado'] ?? null;
        if ($estado !== null && strlen((string) json_encode($estado)) > self::MAX_ESTADO_BYTES) {
            return response()->json([
                'success' => false,
                'message' => 'El estado es demasiado grande.',
            ], 413);
        }

        $resultado = AifResultado::create([
            'usuario'    => $validated['usuario'],
            'modulo'     => $validated['modulo'],
            'evento'     => $validated['evento'],
            'puntaje'    => $validated['puntaje'] ?? null,
            'total'      => $validated['total'] ?? null,
            'tiempo_seg' => $validated['tiempo_seg'] ?? null,
            'estado'     => $estado,
            'cliente_id' => $validated['cliente_id'] ?? null,
            'ip'         => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 400),
        ]);

        return response()->json(['success' => true, 'id' => (string) $resultado->_id], 201);
    }

    /**
     * Lista resultados (admin). Filtros opcionales: usuario, modulo, evento, desde (ISO 8601).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'usuario' => ['nullable', Rule::in(AifResultado::USUARIOS)],
            'modulo'  => ['nullable', Rule::in(AifResultado::MODULOS)],
            'evento'  => ['nullable', Rule::in(AifResultado::EVENTOS)],
            'desde'   => 'nullable|date',
            'limite'  => 'nullable|integer|min:1|max:1000',
        ]);

        $query = AifResultado::query();
        foreach (['usuario', 'modulo', 'evento'] as $campo) {
            if ($request->filled($campo)) {
                $query->where($campo, $request->input($campo));
            }
        }
        if ($request->filled('desde')) {
            $query->where('created_at', '>=', Carbon::parse($request->input('desde')));
        }

        $filas = $query->orderBy('created_at', 'desc')
            ->limit((int) $request->input('limite', 500))
            ->get()
            ->map(fn (AifResultado $r) => [
                'id'         => (string) $r->_id,
                'usuario'    => $r->usuario,
                'modulo'     => $r->modulo,
                'evento'     => $r->evento,
                'puntaje'    => $r->puntaje,
                'total'      => $r->total,
                'tiempo_seg' => $r->tiempo_seg,
                'estado'     => $r->estado,
                'cliente_id' => $r->cliente_id,
                'ip'         => $r->ip,
                'user_agent' => $r->user_agent,
                'fecha'      => optional($r->created_at)->toIso8601String(),
            ]);

        return response()->json(['success' => true, 'total' => $filas->count(), 'resultados' => $filas]);
    }
}
