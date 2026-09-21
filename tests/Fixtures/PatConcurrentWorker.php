<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\DBLayer\DB;
use Infocyph\Epicrypt\Auth\Personal\PersonalAccessTokenRecord;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEpicryptPersonalAccessTokenStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

final class PatConcurrentWorker
{
    public static function run(string $database, string $startSignal, string $operation): int
    {
        try {
            $deadline = microtime(true) + 10.0;
            while (!is_file($startSignal)) {
                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException('PAT concurrency start signal timed out.');
                }
                usleep(1_000);
            }

            DB::purge();
            $store = self::store($database);
            if ($operation === 'issue') {
                $created = $store->create(new PersonalAccessTokenRecord(
                    tokenId: 'concurrent-token',
                    subject: 'account-1',
                    name: 'concurrent',
                    abilities: ['orders.read'],
                    createdAt: 1_700_000_000,
                    expiresAt: 1_700_003_600,
                ));
                if (!$created) {
                    throw new \RuntimeException('Concurrent PAT issue did not create its token.');
                }

                return 0;
            }
            if ($operation === 'revoke') {
                $store->revokeAll('account-1', 1_700_000_100);

                return 0;
            }

            throw new \InvalidArgumentException('Unknown PAT concurrency operation.');
        } catch (\Throwable $exception) {
            fwrite(STDERR, $exception::class . ': ' . $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    public static function factory(string $database): DBLayerFactory
    {
        $state = new RuntimeExecutionState();
        $container = new readonly class($state) implements ContainerInterface {
            public function __construct(private RuntimeExecutionState $state) {}

            public function get(string $id): mixed
            {
                if ($id === RuntimeExecutionState::class) {
                    return $this->state;
                }

                throw new \LogicException(sprintf('PAT worker container has no service "%s".', $id));
            }

            public function has(string $id): bool
            {
                return $id === RuntimeExecutionState::class;
            }
        };
        $config = new ConfigRepository([
            'database' => [
                'default' => 'pat',
                'connections' => [
                    'pat' => ['driver' => 'sqlite', 'database' => $database],
                ],
            ],
        ]);
        $factory = new DBLayerFactory(new DatabaseConnectionResolver($config), $container);
        $factory->connection()->setQueryTimeoutMs(5_000);

        return $factory;
    }

    public static function store(string $database): DBLayerEpicryptPersonalAccessTokenStore
    {
        return new DBLayerEpicryptPersonalAccessTokenStore(self::factory($database), new AuthTables());
    }
}
