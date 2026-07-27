<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection
    |--------------------------------------------------------------------------
    |
    | This value names the connection used when a database operation does not
    | request one explicitly. It must match a key in "connections" below.
    | Shipped values: `mysql|pgsql|sqlite`; custom connection names are allowed.
    |
    */
    'default' => env('DB_CONNECTION', env('DB_DRIVER', 'sqlite')),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | MySQL uses "driver", "host", "port", "database", "username",
    | "password", and "charset". PostgreSQL uses the same network and
    | credential keys. SQLite uses "driver" and an absolute database path.
    | Drivers: `mysql|pgsql|sqlite`. Host example: `db.internal`; ports:
    | MySQL `3306`, PostgreSQL `5432`; database example: `acme`; username:
    | `acme_app`; charset example: `utf8mb4`; SQLite path example:
    | `/srv/acme/database/database.sqlite`. Passwords are free-form secrets and
    | should be supplied by the deployment environment.
    |
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'infbyte'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
        ],
        'pgsql' => [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', 5432),
            'database' => env('DB_DATABASE', 'infbyte'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', ''),
        ],
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
        ],
    ],
];
