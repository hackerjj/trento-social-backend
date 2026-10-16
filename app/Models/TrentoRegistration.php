<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class TrentoRegistration extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'trento_registrations';

    protected $fillable = [
        'nombre',
        'domicilio',
        'telefono',
        'telefono_raw',
        'tipo_residente',
        'email',
        'consentimiento',
        'consent_ts',
        'aviso_version',
        'tracking',
    ];

    protected $casts = [
        'consentimiento' => 'boolean',
        'consent_ts' => 'datetime',
        'tracking' => 'array',
    ];

    public const TIPOS_RESIDENTE = [
        'propietario',
        'inquilino',
    ];
}
