<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\ReqShieldDatabaseProvider;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\Rule;

it('validates database-backed ReqShield 3.0.2 rules through DBLayer', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-validation-db-' . uniqid('', true);
    mkdir($basePath . '/database', 0775, true);

    $app = Foundation::web([
        'app' => ['base_path' => $basePath],
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
                    'email' => ['required', 'email', Rule::unique('users', 'email')->ignore(1)],
                ],
                'users.restore' => [
                    'email' => ['required', 'email', Rule::unique('users', 'email')->withoutTrashed()],
                ],
            ],
        ],
    ])->boot();

    $database = $app->make(DBLayerFactory::class)->connection();
    $validators = $app->make(ValidatorFactory::class);

    try {
        $pdo = $database->getPdo();
        $pdo->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT NOT NULL, deleted_at TEXT NULL)');
        $pdo->exec('INSERT INTO categories (id) VALUES (1)');
        $pdo->exec("INSERT INTO users (id, email, deleted_at) VALUES (1, 'ada@example.test', NULL)");
        $pdo->exec("INSERT INTO users (id, email, deleted_at) VALUES (2, 'archived@example.test', '2026-01-01')");

        expect($validators->make('users.create')->validate([
            'category_id' => 1,
            'email' => 'new@example.test',
        ])->fails())->toBeFalse();

        $invalid = $validators->make('users.create')->validate([
            'category_id' => 404,
            'email' => 'ada@example.test',
        ]);

        expect($invalid->fails())->toBeTrue()
            ->and($invalid->errors())->toHaveKeys(['category_id', 'email'])
            ->and($validators->make('users.update')->validate([
                'email' => 'ada@example.test',
            ])->fails())->toBeFalse()
            ->and($validators->make('users.restore')->validate([
                'email' => 'archived@example.test',
            ])->fails())->toBeFalse();

        $provider = $app->make(ReqShieldDatabaseProvider::class);
        expect($provider->batchExists('categories', [
            ['column' => 'id', 'value' => 1, 'field' => 'present'],
            ['column' => 'id', 'value' => 404, 'field' => 'missing'],
        ]))->toBe(['missing'])
            ->and($provider->batchUnique('users', [
                [
                    'column' => 'email', 'value' => 'ada@example.test', 'field' => 'email',
                    'ignore' => 1, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
                [
                    'column' => 'email', 'value' => 'ada@example.test', 'field' => 'duplicate',
                    'ignore' => null, 'id_column' => 'id', 'include_trashed' => false,
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
        foundationReqShieldDatabaseRemove($basePath);
    }
});

function foundationReqShieldDatabaseRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $entries = scandir($directory);
    if ($entries === false) {
        return;
    }
    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            foundationReqShieldDatabaseRemove($path);
        } else {
            unlink($path);
        }
    }
    rmdir($directory);
}
