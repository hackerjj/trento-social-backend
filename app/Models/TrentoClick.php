<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class TrentoClick extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'trento_clicks';

    // Un documento por clic; no se actualiza, solo se inserta y se agrega en stats.
    public $timestamps = false;

    protected $fillable = [
        'code',
        'ts',
        'ip',
        'geo',
        'device',
        'browser',
        'os',
        'referrer',
        'user_agent',
    ];

    protected $casts = [
        'ts' => 'datetime',
        'geo' => 'array',
    ];
}
