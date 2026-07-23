<?php

declare(strict_types=1);

use Infocyph\Foundation\Facades\DB;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\ReqShieldDatabaseProvider;

it('validates database-backed reqshield rules through DBLayer', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-validation-db-' . uniqid('', true);
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => [
            'base_path' => $basePath,
        ],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/validation.sqlite',
                ],
            ],
        ],
        'validation' => [
            'schemas' => [
                'users.create' => [
                    'category_id' => 'required|integer|exists:categories,id',
                    'email' => 'required|email|unique:users,email',
                ],
                'users.update' => [
                    'email' => 'required|email|unique:users,email,1',
                ],
                'users.restore' => [
                    'email' => 'required|email|unique:users,email,,id,false,deleted_at',
                ],
            ],
        ],
    ])->boot();

    try {
        DB::pdo()->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        DB::pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, deleted_at TEXT NULL)');
        DB::pdo()->exec("INSERT INTO categories (id) VALUES (1)");
        DB::pdo()->exec("INSERT INTO users (id, email, deleted_at) VALUES (1, 'ada@example.test', NULL)");
        DB::pdo()->exec("INSERT INTO users (id, email, deleted_at) VALUES (2, 'archived@example.test', '2026-01-01')");

        expect($app->validator()->validate('users.create', [
            'category_id' => 1,
            'email' => 'new@example.test',
        ])->fails())->toBeFalse();

        $invalid = $app->validator()->validate('users.create', [
            'category_id' => 404,
            'email' => 'ada@example.test',
        ]);

        expect($invalid->fails())->toBeTrue()
            ->and($invalid->errors())->toHaveKeys(['category_id', 'email'])
            ->and($app->validator()->validate('users.update', [
                'email' => 'ada@example.test',
            ])->fails())->toBeFalse()
            ->and($app->validator()->validate('users.restore', [
                'email' => 'archived@example.test',
            ])->fails())->toBeFalse();

        /** @var ReqShieldDatabaseProvider $provider */
        $provider = $app->make(ReqShieldDatabaseProvider::class);

        expect($provider->batchExistsCheck('categories', [
            ['column' => 'id', 'value' => 1, 'field' => 'present'],
            ['column' => 'id', 'value' => 404, 'field' => 'missing'],
        ]))->toBe(['missing'])
            ->and($provider->batchUniqueCheck('users', [
                [
                    'column' => 'email',
                    'value' => 'ada@example.test',
                    'field' => 'email',
                    'ignore_id' => 1,
                    'id_column' => 'id',
                    'with_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
                [
                    'column' => 'email',
                    'value' => 'ada@example.test',
                    'field' => 'duplicate',
                    'ignore_id' => null,
                    'id_column' => 'id',
                    'with_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
            ]))->toBe(['duplicate'])
            ->and($provider->compositeUnique('users', [
                'email' => 'ada@example.test',
                'deleted_at' => null,
            ]))->toBeFalse()
            ->and($provider->compositeUnique('users', [
                'email' => 'ada@example.test',
                'deleted_at' => null,
            ], 1))->toBeTrue()
            ->and($provider->query('SELECT id FROM users WHERE email = ?', ['ada@example.test']))->toBe([
                ['id' => 1],
            ]);
    } finally {
        DB::purge();
    }
});
