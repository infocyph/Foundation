<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Database;

use Infocyph\CacheLayer\Cache\Lock\LockProviderInterface;
use Infocyph\DBLayer\Migration\Migration;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\DBLayer\Migration\Seeder;
use Infocyph\DBLayer\Migration\SeedRunner;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Psr\Container\ContainerInterface;

final readonly class DatabaseMigrationManager
{
    public function __construct(
        private ConfigRepository $config,
        private DBLayerFactory $factory,
        private ContainerInterface $services,
        private ?CacheLayerFactory $cache = null,
    ) {}

    public function runner(?string $connection = null): MigrationRunner
    {
        return new MigrationRunner(
            connection: $this->factory->connection($connection),
            migrations: $this->migrations(),
            locks: $this->locks(),
            table: ValueNormalizer::string(
                $this->config->get('database.migrations.table'),
                'migrations',
            ),
            lockWaitSeconds: $this->floatConfig('database.migrations.lock_wait_seconds', 10.0),
            leaseSeconds: $this->floatConfig('database.migrations.lock_lease_seconds', 300.0),
        );
    }

    public function seed(?string $connection = null, bool $transactional = true): int
    {
        return new SeedRunner($this->factory->connection($connection))->run(
            $this->seeders(),
            $transactional,
        );
    }

    /** @return list<class-string> */
    private function definitions(string $key): array
    {
        $configured = $this->config->get($key, []);
        if (!is_array($configured)) {
            throw new \InvalidArgumentException($key . ' must be an explicit class list.');
        }

        $definitions = [];
        foreach ($configured as $definition) {
            if (!is_string($definition) || (!class_exists($definition) && !interface_exists($definition))) {
                throw new \InvalidArgumentException($key . ' contains an unavailable class.');
            }
            $definitions[] = $definition;
        }

        return $definitions;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function locks(): ?LockProviderInterface
    {
        $store = ValueNormalizer::nullableString($this->config->get('database.migrations.lock_store'));
        if ($store === null) {
            return null;
        }
        if (!$this->cache instanceof CacheLayerFactory) {
            throw new \LogicException(
                'Database migration locks require the Foundation cache capability.',
            );
        }

        return $this->cache->lock($store);
    }

    /** @return list<Migration> */
    private function migrations(): array
    {
        $migrations = [];
        foreach ($this->definitions('database.migrations.classes') as $definition) {
            $migration = $this->resolve($definition);
            if (!$migration instanceof Migration) {
                throw new \InvalidArgumentException(sprintf(
                    'Database migration "%s" must implement %s.',
                    $definition,
                    Migration::class,
                ));
            }
            $migrations[] = $migration;
        }

        return $migrations;
    }

    private function resolve(string $definition): object
    {
        $resolved = $this->services->get($definition);
        if (!is_object($resolved)) {
            throw new \UnexpectedValueException(sprintf(
                'Database definition "%s" did not resolve to an object.',
                $definition,
            ));
        }

        return $resolved;
    }

    /** @return list<Seeder|callable> */
    private function seeders(): array
    {
        $seeders = [];
        foreach ($this->definitions('database.seeders') as $definition) {
            $seeder = $this->resolve($definition);
            if (!$seeder instanceof Seeder && !is_callable($seeder)) {
                throw new \InvalidArgumentException(sprintf(
                    'Database seeder "%s" must implement %s or be callable.',
                    $definition,
                    Seeder::class,
                ));
            }
            $seeders[] = $seeder;
        }

        return $seeders;
    }
}
