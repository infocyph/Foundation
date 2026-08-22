<?php

declare(strict_types=1);

use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\DB;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Foundation;

it('resets shared and fresh DBLayer connections at execution boundaries', function (): void {
    $app = Foundation::worker([
        'database' => [
            'default' => 'runtime',
            'connections' => [
                'runtime' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ],
    ]);

    $shared = $app->make(Connection::class);
    $fresh = null;

    try {
        $app->execution()->run(static function () use ($app, $shared, &$fresh): void {
            $shared->begin();
            $fresh = $app->make(DBLayerFactory::class)->connection(fresh: true);
            $fresh->begin();

            expect($shared->transactionLevel())->toBe(1)
                ->and($fresh->transactionLevel())->toBe(1);
        });

        expect($shared->transactionLevel())->toBe(0)
            ->and($fresh)->toBeInstanceOf(Connection::class)
            ->and($fresh->transactionLevel())->toBe(0);
    } finally {
        $fresh?->disconnect();
        DB::purge();
    }
});
