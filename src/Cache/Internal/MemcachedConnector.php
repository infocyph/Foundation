<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache\Internal;

use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

/** Native Memcached connection and server-address materialization. */
final class MemcachedConnector
{
    /**
     * @param array<string,mixed> $store
     * @param array<string,mixed> $lock
     */
    public static function client(array $store, array $lock): \Memcached
    {
        $client = $lock['client'] ?? $store['client'] ?? null;
        if ($client instanceof \Memcached) {
            return $client;
        }
        if (!class_exists(\Memcached::class)) {
            throw new ConfigurationException('Memcached lock provider requires the Memcached extension.');
        }

        $client = new \Memcached();
        if (!$client->addServers(self::servers($lock['servers'] ?? $store['servers'] ?? null))) {
            throw new ConfigurationException('Memcached lock provider could not configure its servers.');
        }

        return $client;
    }

    /** @return list<array{0:string,1:int,2:int}> */
    public static function servers(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            return [['127.0.0.1', 11211, 0]];
        }

        $servers = [];
        foreach ($value as $server) {
            if (is_array($server) && isset($server[0], $server[1]) && is_string($server[0])) {
                $servers[] = [
                    $server[0],
                    ValueNormalizer::int($server[1], 11211),
                    ValueNormalizer::int($server[2] ?? null, 0),
                ];

                continue;
            }

            $server = ValueNormalizer::associativeArray($server);
            $servers[] = [
                ValueNormalizer::string($server['host'] ?? null, '127.0.0.1'),
                ValueNormalizer::int($server['port'] ?? null, 11211),
                ValueNormalizer::int($server['weight'] ?? null, 0),
            ];
        }

        return $servers;
    }
}
