<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class CacheLayerFactory
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function make(?string $name = null): CacheInterface
    {
        $name ??= $this->stringConfig('cache.default', 'memory');
        $stores = $this->config->get('cache.stores', []);

        if (is_array($stores) && isset($stores[$name]) && is_array($stores[$name])) {
            return $this->makeFromStoreConfig($name, ValueNormalizer::associativeArray($stores[$name]));
        }

        return $this->makeFromStoreConfig($name, ['driver' => $name]);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function makeFromStoreConfig(string $name, array $store): CacheInterface
    {
        $driverName = isset($store['driver']) && is_string($store['driver'])
            ? $store['driver']
            : $name;
        $driver = CacheDriver::tryFrom($driverName);
        if ($driver === null) {
            throw new ConfigurationException(sprintf(
                'Invalid cache store "%s" driver "%s".',
                $name,
                $driverName,
            ));
        }

        $namespace = isset($store['namespace']) && is_string($store['namespace'])
            ? $store['namespace']
            : 'foundation:' . $name;

        return match ($driver) {
            CacheDriver::APCU => Cache::apcu($namespace),
            CacheDriver::FILE => Cache::file($namespace, isset($store['dir']) && is_string($store['dir']) ? $store['dir'] : null),
            CacheDriver::LOCAL => Cache::local($namespace, isset($store['dir']) && is_string($store['dir']) ? $store['dir'] : null),
            CacheDriver::MEMORY => Cache::memory($namespace),
        };
    }

    private function stringConfig(string $key, string $default): string
    {
        $value = $this->config->get($key, $default);

        return is_string($value) ? $value : $default;
    }
}
