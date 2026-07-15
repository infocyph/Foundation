<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class RedisConnectionFactory
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    /**
     * @param array<string, mixed> $definition
     */
    public function client(array $definition, string $driver): \Redis
    {
        $connection = $this->connection($definition);
        if ($connection['client'] instanceof \Redis) {
            return $connection['client'];
        }

        if (!class_exists(\Redis::class)) {
            throw new ConfigurationException(sprintf('%s requires the phpredis extension.', ucfirst($driver)));
        }

        $parts = parse_url($connection['dsn']);
        if (!is_array($parts)) {
            throw new ConfigurationException(sprintf('Invalid %s connection DSN.', ucfirst($driver)));
        }

        $client = new \Redis();
        $client->connect(
            is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1',
            is_int($parts['port'] ?? null) ? $parts['port'] : 6379,
        );
        if (is_string($parts['pass'] ?? null) && $parts['pass'] !== '') {
            $client->auth($parts['pass']);
        }
        if (is_string($parts['path'] ?? null) && $parts['path'] !== '/') {
            $client->select((int) ltrim($parts['path'], '/'));
        }

        return $client;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array{client:?\Redis,dsn:string}
     */
    public function connection(array $definition): array
    {
        $connection = $definition['connection'] ?? null;
        $configured = is_string($connection) && $connection !== ''
            ? ValueNormalizer::associativeArray($this->config->get('cache.connections.' . $connection, []))
            : [];
        $resolved = array_replace($configured, $definition);
        $client = $resolved['client'] ?? null;

        return [
            'client' => $client instanceof \Redis ? $client : null,
            'dsn' => ValueNormalizer::string(
                $resolved['dsn'] ?? null,
                strtolower(ValueNormalizer::string($resolved['driver'] ?? null)) === 'valkey'
                    ? 'valkey://127.0.0.1:6379'
                    : 'redis://127.0.0.1:6379',
            ),
        ];
    }
}
