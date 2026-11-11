<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Events\DatabaseEvents\QueryFailed;
use Infocyph\DBLayer\Events\Events;
use Infocyph\DBLayer\Exceptions\ConnectionException;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;

it('rolls back failed statements while retaining the error and reuses native DBLayer connections', function (
    string $driver,
    ?string $dsnVariable,
    ?string $usernameVariable,
    ?string $passwordVariable,
    bool $statementCacheEnabled,
): void {
    $connection = ['driver' => $driver, 'database' => ':memory:'];

    if ($dsnVariable !== null) {
        $dsn = getenv($dsnVariable);
        if (!is_string($dsn) || !str_starts_with($dsn, $driver . ':')) {
            throw new RuntimeException(sprintf('Native database recovery test requires %s.', $dsnVariable));
        }

        $properties = [];
        foreach (explode(';', substr($dsn, strlen($driver) + 1)) as $part) {
            $separator = strpos($part, '=');
            if ($separator !== false) {
                $properties[substr($part, 0, $separator)] = substr($part, $separator + 1);
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

    $connection['statement_cache_enabled'] = $statementCacheEnabled;
    $connection['statement_cache_size'] = 8;

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
        $failedEvents = [];
        $listener = static function (QueryFailed $event) use ($table, &$failedEvents): void {
            if (str_contains($event->sql, $table)) {
                $failedEvents[] = $event;
            }
        };
        Events::listen('db.query.failed', $listener);

        try {
            expect($database->statement(
                "CREATE TABLE {$table} (id INTEGER PRIMARY KEY, payload VARCHAR(64) NOT NULL)",
            ))->toBeTrue();

            $pdo = $database->getPdo();
            $insert = "INSERT INTO {$table} (id, payload) VALUES (?, ?)";
            $database->beginTransaction();
            $database->statement($insert, [1, 'rolled-back']);

            // Preserve the original failure and its PDO stack while the same
            // connection rolls back and is used by a later transaction.
            $retainedFailure = null;
            try {
                $database->statement($insert, [1, 'duplicate']);
            } catch (ConnectionException $failure) {
                $retainedFailure = $failure;
            }

            expect($retainedFailure)->toBeInstanceOf(ConnectionException::class)
                ->and($failedEvents)->toHaveCount(1)
                ->and($failedEvents[0]->exception)->toBe($retainedFailure)
                ->and($failedEvents[0]->connection)->toBe($database)
                ->and($failedEvents[0]->attempts)->toBeGreaterThanOrEqual(1)
                ->and($database->transactionLevel())->toBe(1);

            $database->rollbackTransaction();

            $rolledBackCount = $database->scalar("SELECT COUNT(*) FROM {$table}");
            if (!is_numeric($rolledBackCount)) {
                throw new \UnexpectedValueException('Rollback must provide a numeric row count.');
            }
            expect($database->transactionLevel())->toBe(0)
                ->and((int) $rolledBackCount)->toBe(0)
                ->and($factory->connection())->toBe($database)
                ->and($database->getPdo())->toBe($pdo);

            $result = $database->transaction(
                static function (Connection $current) use ($insert): string {
                    // Reuse the failed prepared SQL after rollback, including with statement caching on.
                    $current->statement($insert, [1, 'committed']);

                    return 'committed';
                },
                attempts: 2,
            );

            expect($result)->toBe('committed')
                ->and((int) $database->scalar("SELECT COUNT(*) FROM {$table}"))->toBe(1)
                ->and($database->scalar("SELECT payload FROM {$table} WHERE id = ?", [1]))->toBe('committed')
                ->and($database->getPdo())->toBe($pdo)
                ->and($database->transactionLevel())->toBe(0)
                ->and($retainedFailure)->toBeInstanceOf(ConnectionException::class);
        } finally {
            Events::forget('db.query.failed', $listener);
            while ($database->transactionLevel() > 0) {
                $database->rollbackTransaction();
            }
            $database->statement("DROP TABLE IF EXISTS {$table}");
        }
    });
})->with([
    'sqlite uncached' => ['sqlite', null, null, null, false],
    'sqlite cached' => ['sqlite', null, null, null, true],
    'mysql uncached' => ['mysql', 'IC_MYSQL_DSN', 'IC_MYSQL_USER', 'IC_MYSQL_PASSWORD', false],
    'mysql cached' => ['mysql', 'IC_MYSQL_DSN', 'IC_MYSQL_USER', 'IC_MYSQL_PASSWORD', true],
    'postgresql uncached' => ['pgsql', 'IC_POSTGRES_DSN', 'IC_POSTGRES_USER', 'IC_POSTGRES_PASSWORD', false],
    'postgresql cached' => ['pgsql', 'IC_POSTGRES_DSN', 'IC_POSTGRES_USER', 'IC_POSTGRES_PASSWORD', true],
]);

it('releases failed SQLite write contention for a later transaction while retaining the failure event', function (): void {
    $file = sys_get_temp_dir() . '/foundation-db-contention-' . bin2hex(random_bytes(8)) . '.sqlite';
    $app = Foundation::worker([
        'app' => ['env' => 'testing', 'capabilities' => ['database']],
        'database' => [
            'default' => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => $file],
            ],
        ],
    ])->boot();
    $table = 'foundation_lock_' . bin2hex(random_bytes(6));
    $captured = [];

    try {
        $app->execution()->run(static function () use ($app, $table, &$captured): void {
            $factory = $app->make(DBLayerFactory::class);
            $writer = $factory->connection();
            $contender = $factory->connection(fresh: true);
            $listener = static function (QueryFailed $event) use ($table, &$captured): void {
                if (str_contains($event->sql, $table)) {
                    $captured[] = $event;
                }
            };
            Events::listen('db.query.failed', $listener);

            try {
                $writer->statement("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, payload TEXT NOT NULL)");
                $contenderPdo = $contender->getPdo();
                $contenderPdo->exec('PRAGMA busy_timeout = 30');
                $insert = "INSERT INTO {$table} (id, payload) VALUES (?, ?)";
                $writer->beginTransaction();
                $writer->statement("INSERT INTO {$table} (id, payload) VALUES (?, ?)", [1, 'writer']);

                $retainedFailure = null;
                try {
                    $contender->statement($insert, [2, 'blocked']);
                } catch (ConnectionException $failure) {
                    $retainedFailure = $failure;
                }

                expect($retainedFailure)->toBeInstanceOf(ConnectionException::class)
                    ->and($captured)->toHaveCount(1)
                    ->and($captured[0]->exception)->toBe($retainedFailure)
                    ->and($captured[0]->connection)->toBe($contender)
                    ->and($contender->getPdo())->toBe($contenderPdo);

                $writer->rollbackTransaction();

                expect($contender->statement($insert, [2, 'after-rollback']))->toBeTrue()
                    ->and((int) $contender->scalar("SELECT COUNT(*) FROM {$table}"))->toBe(1)
                    ->and($contender->scalar("SELECT payload FROM {$table} WHERE id = ?", [2]))
                    ->toBe('after-rollback')
                    ->and($contender->getPdo())->toBe($contenderPdo)
                    ->and($captured)->toHaveCount(1);
            } finally {
                Events::forget('db.query.failed', $listener);
                while ($writer->transactionLevel() > 0) {
                    $writer->rollbackTransaction();
                }
                while ($contender->transactionLevel() > 0) {
                    $contender->rollbackTransaction();
                }
                $writer->statement("DROP TABLE IF EXISTS {$table}");
            }
        });
    } finally {
        unset($app);
        foreach ([$file, $file . '-shm', $file . '-wal'] as $candidate) {
            if (is_file($candidate)) {
                unlink($candidate);
            }
        }
    }
});
