<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\DBLayer\Connection\Connection;
use Infocyph\DBLayer\Connection\ConnectionConfig;
use Infocyph\Webrick\Response\Response;
use RuntimeException;

/**
 * Selected native storage and credential workload, using an isolated fixture.
 * This is not a full application authentication or authorization transaction.
 */
final readonly class Phase9NativeReadHandler
{
    public static function read(): Response
    {
        $root = getenv('FOUNDATION_HOST_APP');
        if (!is_string($root) || $root === '') {
            throw new RuntimeException('Missing Foundation native benchmark fixture.');
        }

        $connection = new Connection(ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $root . '/storage/benchmark.sqlite',
        ]));
        try {
            $records = $connection->select(
                'SELECT item_value FROM benchmark_items WHERE id = ?',
                [42],
            );
        } finally {
            $connection->disconnect();
        }

        $cached = Cache::file('foundation-host', $root . '/storage/cache')->get('item-42');
        $expected = hash('sha256', 'foundation-native-fixture');
        $digest = hash('sha256', 'foundation-native-fixture');
        if (($records[0]['item_value'] ?? null) !== 'value-42'
            || $cached !== 'cached-42'
            || !hash_equals($expected, $digest)
        ) {
            throw new RuntimeException('Native data integrity validation failed.');
        }

        return Response::json(['ok' => true]);
    }
}
