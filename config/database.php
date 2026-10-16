<?php

return [

    'default' => env('DB_CONNECTION', 'mongodb'),

    'connections' => [

        'mongodb' => [
            'driver' => 'mongodb',
            'dsn' => env('MONGO_URI', env('MONGO_DSN')),
            'host' => env('MONGO_HOST', '127.0.0.1'),
            'port' => env('MONGO_PORT', 27017),
            'database' => env('MONGO_DATABASE', 'sushi_queen'),
            'username' => env('MONGO_USERNAME', ''),
            'password' => env('MONGO_PASSWORD', ''),
            'options' => [
                'authSource' => env('MONGO_AUTH_SOURCE', 'admin'),
            ],
        ],

    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [

        'client' => env('REDIS_CLIENT', 'predis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            // Prefijo de cliente Predis: se antepone a TODAS las claves de las
            // conexiones que lo heredan (default/cache/session). Aisla las claves
            // de framework de Trento (cache, rate-limit) bajo 'trento:' sin tocarlas
            // a mano, sin chocar con el 'sq_' de Sushi.
            'prefix' => env('REDIS_PREFIX', 'trento:'),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '0'),
        ],

        'session' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SESSION_DB', '0'),
        ],

        // Conexion dedicada a contadores del acortador y de registros.
        // MISMO servidor/base que 'default', pero con 'options.prefix' VACIO: como el
        // prefix de Predis es a nivel de cliente y Laravel crea un cliente por conexion,
        // 'counters' NO antepone nada. Asi se escriben claves literales como
        // 'trento:link:{code}:clicks' sin duplicar el prefijo ('trento:trento:...').
        'counters' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'options' => ['prefix' => ''],
        ],

    ],

];
