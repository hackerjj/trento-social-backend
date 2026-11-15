<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

/**
 * Resultados del curso AIF-C01 (sitios de estudio de Jair y Gaby).
 * Colección aislada; no tiene relación con los datos de Trento.
 */
class AifResultado extends Model
{
    protected $connection = 'mongodb';

    protected $collection = 'aif_resultados';

    protected $fillable = [
        'usuario',
        'modulo',
        'evento',
        'puntaje',
        'total',
        'tiempo_seg',
        'estado',
        'cliente_id',
        'ip',
        'user_agent',
    ];

    protected $casts = [
        'estado' => 'array',
    ];

    public const USUARIOS = ['gaby', 'jair'];

    public const MODULOS = ['diagnostico', 'guia1', 'guia2', 'practica', 'simulacro1', 'simulacro2', 'snapshot'];

    public const EVENTOS = ['avance', 'terminado', 'snapshot'];
}
