<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Validation\ValidatorFactory;
use Infocyph\ReqShield\Bridge\DBLayerDatabaseProvider;
use Infocyph\ReqShield\Rule;

it('validates database-backed ReqShield 3.2 rules through the native DBLayer 5.1 bridge', function (): void {
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
                    'security' => [
                        'max_params' => 3,
                    ],
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
        $pdo->exec('INSERT INTO categories (id) VALUES (1), (2), (3), (4), (5)');
        $pdo->exec("INSERT INTO users (id, email, deleted_at) VALUES (1, 'ada@example.test', NULL)");
        $pdo->exec("INSERT INTO users (id, email, deleted_at) VALUES (2, 'archived@example.test', '2026-01-01')");

        expect($database->effectiveMaxBindParameters())->toBe(3)
            ->and($validators->make('users.create')->validate([
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

        $provider = $app->make(DBLayerDatabaseProvider::class);
        expect(fn() => $provider->batchExists('categories; DROP TABLE users', [
            ['id' => 1, 'column' => 'id', 'value' => 1, 'field' => 'unsafe-table'],
        ]))->toThrow(InvalidArgumentException::class)
            ->and(fn() => $provider->batchExists('categories', [
                ['id' => 1, 'column' => 'id) OR 1=1 --', 'value' => 1, 'field' => 'unsafe-column'],
            ]))->toThrow(InvalidArgumentException::class)
            ->and($provider->batchExists('categories', [
            ['id' => 1, 'column' => 'id', 'value' => 1, 'field' => 'one'],
            ['id' => 2, 'column' => 'id', 'value' => 2, 'field' => 'two'],
            ['id' => 3, 'column' => 'id', 'value' => 3, 'field' => 'three'],
            ['id' => 4, 'column' => 'id', 'value' => 4, 'field' => 'four'],
            ['id' => 5, 'column' => 'id', 'value' => 5, 'field' => 'five'],
            ['id' => 6, 'column' => 'id', 'value' => 404, 'field' => 'missing'],
        ]))->toBe([6])
            ->and($provider->batchUnique('users', [
                [
                    'id' => 1, 'column' => 'email', 'value' => 'ada@example.test', 'field' => 'ignored-owner',
                    'ignore' => 1, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
                [
                    'id' => 2, 'column' => 'email', 'value' => 'new@example.test', 'field' => 'new-one',
                    'ignore' => 1, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
                [
                    'id' => 3, 'column' => 'email', 'value' => 'other@example.test', 'field' => 'new-two',
                    'ignore' => 1, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
                [
                    'id' => 4, 'column' => 'email', 'value' => 'archived@example.test', 'field' => 'archived',
                    'ignore' => 1, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
            ]))->toBe([])
            ->and($provider->batchUnique('users', [
                [
                    'id' => 5, 'column' => 'email', 'value' => 'ada@example.test', 'field' => 'duplicate',
                    'ignore' => null, 'id_column' => 'id', 'include_trashed' => false,
                    'soft_delete_column' => 'deleted_at',
                ],
            ]))->toBe([5]);
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
