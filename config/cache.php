<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | En produccion debe quedar en "redis" para que el rate limiting y el cache
    | compartan estado entre procesos. start.sh degrada a "file" si Redis no
    | responde al arranque, para que un Redis caido no tumbe el servicio.
    |
    */

    'default' => env('CACHE_DRIVER', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    */

    'stores' => [

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
            'lock_connection' => 'default',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Aisla las claves de cache de Trento frente a Sushi. Junto con el
    | REDIS_PREFIX='trento:' de config/database.php, ninguna clave de framework
    | de Trento choca con 'sushi_queen_cache_' ni con 'sq_'.
    |
    */

    'prefix' => env('CACHE_PREFIX', 'trento_cache_'),

];
