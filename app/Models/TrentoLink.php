<?php

namespace App\Models;

use MongoDB\Laravel\Eloquent\Model;

class TrentoLink extends Model
{
    protected $connection = 'mongodb';
    protected $collection = 'trento_links';

    protected $fillable = [
        'code',
        'destination',
        'label',
        'active',
    ];

    protected $casts = [
        'active' => 'boolean',
    ];
}
