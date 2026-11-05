<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;

it('rolls back failed statements while retaining the error and reuses native DBLayer connections', function (
    string $driver,
    ?string $dsnVariable,
    ?string $usernameVariable,
    ?string $passwordVariable,
): void {
    $connection = ['driver' => $driver, 'database' => ':memory:'];

    if ($dsnVariable !== null) {
        $dsn = getenv($dsnVariable);
        if (!is_string($dsn) || !str_starts_with($dsn, $driver . ':')) {
            throw new RuntimeException(sprintf('Native database recovery test requires %s.', $dsnVariable));
        }

        $properties = [];
        foreach (explode(';', substr($dsn, strlen($driver) + 1)) as $part) {
            if (str_contains($part, '=')) {
                [$key, $value] = explode('=', $part, 2);
                $properties[$key] = $value;
            }
        }

        $database = $properties['dbname'] ?? null;
        if (!is_string($database) || $database === '') {
            throw new RuntimeException(sprintf('%s must include a database name.', $dsnVariable));
        }
        $username = getenv((string) $usernameVariable);
        $password = getenv((string) $passwordVariable);
        $connection = [
            'driver' => $driver,
            'host' => $properties['host'] ?? '127.0.0.1',
            'port' => (int) ($properties['port'] ?? ($driver === 'mysql' ? '3306' : '5432')),
            'database' => $database,
            'username' => is_string($username) ? $username : '',
            'password' => is_string($password) ? $password : '',
        ];
    }

    $app = Foundation::worker([
        'app' => ['env' => 'testing', 'capabilities' => ['database']],
        'database' => [
            'default' => 'recovery',
            'connections' => ['recovery' => $connection],
        ],
    ])->boot();

    $table = 'f4_failed_statement_' . bin2hex(random_bytes(6));

    $app->execution()->run(static function () use ($app, $table): void {
        $factory = $app->make(DBLayerFactory::class);
        $database = $factory->connection();

        try {
            expect($database->statement(
                "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, payload VARCHAR(64) NOT NULL)",
            ))->toBeTrue();

            $database->beginTransaction();
            $database->statement("INSERT INTO {$table} (id, payload) VALUES (?, ?)", [1, 'rolled-back']);

            // Preserve the original failure and its PDO stack while the same
            // connection rolls back and is used by a later transaction.
            $retainedFailure = null;
            try {
                $database->statement("INSERT INTO {$table} (id, payload) VALUES (?, ?)", [1, 'duplicate']);
            } catch (ConnectionException $failure) {
                $retainedFailure = $failure;
            }

            expect($retainedFailure)->toBeInstanceOf(ConnectionException::class)
                ->and($database->transactionLevel())->toBe(1);

            $database->rollbackTransaction();

            expect($database->transactionLevel())->toBe(0)
                ->and((int) $database->scalar("SELECT COUNT(*) FROM {$table}"))->toBe(0)
                ->and($factory->connection())->toBe($database);

            $result = $database->transaction(
                static function (Connection $current) use ($table): string {
                    $current->statement("INSERT INTO {$table} (id, payload) VALUES (?, ?)", [2, 'committed']);

                    return 'committed';
                },
                attempts: 2,
            );

            expect($result)->toBe('committed')
                ->and((int) $database->scalar("SELECT COUNT(*) FROM {$table}"))->toBe(1)
                ->and($database->scalar("SELECT payload FROM {$table} WHERE id = ?", [2]))->toBe('committed')
                ->and($database->transactionLevel())->toBe(0)
                ->and($retainedFailure)->toBeInstanceOf(ConnectionException::class);
        } finally {
            while ($database->transactionLevel() > 0) {
                $database->rollbackTransaction();
            }
            $database->statement("DROP TABLE IF EXISTS {$table}");
        }
    });
})->with([
    'sqlite' => ['sqlite', null, null, null],
    'mysql' => ['mysql', 'IC_MYSQL_DSN', 'IC_MYSQL_USER', 'IC_MYSQL_PASSWORD'],
    'postgresql' => ['pgsql', 'IC_POSTGRES_DSN', 'IC_POSTGRES_USER', 'IC_POSTGRES_PASSWORD'],
]);
