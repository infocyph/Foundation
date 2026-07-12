<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\Foundation\Facades\DB;
use Infocyph\Foundation\Foundation;

it('surfaces DBLayer repositories and query observability through Foundation', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-db-' . uniqid('', true);
    mkdir($basePath . '/database', 0775, true);
    mkdir($basePath . '/storage/cache', 0775, true);

    $events = [];

    Foundation::create([
        'app' => [
            'base_path' => $basePath,
        ],
        'database' => [
            'default' => 'main',
            'connections' => [
                'main' => [
                    'driver' => 'sqlite',
                    'database' => 'database/foundation.sqlite',
                ],
            ],
        ],
    ]);

    try {
        expect(DB::freshConnection())->toBeInstanceOf(Connection::class);

        DB::enableQueryLog();
        DB::enableTelemetry();
        DB::setTelemetryBufferLimits(32, 32);
        DB::listen(static function (array $event) use (&$events): void {
            $events[] = $event;
        });

        DB::pdo()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, email TEXT NOT NULL)');

        $created = DB::repository('users')->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
        ]);

        $fetched = DB::withQueryTimeout(1_000, static fn(): mixed => DB::repository('users')->find($created['id']));

        DB::beginTransaction();
        expect(DB::transactionLevel())->toBe(1);
        DB::rollback();

        DB::disconnect();
        expect(DB::reconnect())->toBeInstanceOf(Connection::class);

        $count = DB::withQueryCancellation(
            static fn(): bool => false,
            static fn(): int => DB::table('users')->count(),
        );

        $ping = DB::ping();
        $driverName = DB::driverName();
        $databaseName = DB::databaseName();
        $stats = DB::stats();
        $queryLog = DB::queryLog();
        $telemetry = DB::telemetry();
        $flushed = DB::flushTelemetry();
        $telemetryAfterFlush = DB::telemetry();

        expect($created['name'])->toBe('Ada Lovelace')
            ->and($fetched)->toBeArray()
            ->and($fetched['email'] ?? null)->toBe('ada@example.test')
            ->and($count)->toBe(1)
            ->and($ping)->toBeTrue()
            ->and($driverName)->toBe('sqlite')
            ->and($databaseName)->toEndWith('foundation.sqlite')
            ->and($stats['driver'])->toBe('sqlite')
            ->and($stats['transaction_level'])->toBe(0)
            ->and($queryLog)->not->toBeEmpty()
            ->and($events)->not->toBeEmpty()
            ->and($telemetry)->toHaveKey('queries')
            ->and($telemetry['queries'])->not->toBeEmpty()
            ->and($flushed['queries'])->not->toBeEmpty()
            ->and($telemetryAfterFlush['queries'])->toBeEmpty();
    } finally {
        DB::disableTelemetry();
        DB::disableQueryLog();
        DB::flushQueryLog();
        DB::purge();
    }
});
