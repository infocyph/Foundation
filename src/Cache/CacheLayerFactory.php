<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\Cache;
use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class CacheLayerFactory
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function make(?string $name = null): CacheInterface
    {
        $name ??= (string) $this->config->get('cache.default', 'memory');
        $stores = $this->config->get('cache.stores', []);

        if (is_array($stores) && isset($stores[$name]) && is_array($stores[$name])) {
            return $this->makeFromStoreConfig($name, $stores[$name]);
        }

        return $this->makeFromStoreConfig($name, ['driver' => $name]);
    }

    /**
     * @param array<string, mixed> $store
     */
    private function makeFromStoreConfig(string $name, array $store): CacheInterface
    {
        $driver = CacheDriver::tryFrom((string) ($store['driver'] ?? $name));
        if ($driver === null) {
            throw new ConfigurationException(sprintf(
                'Invalid cache store "%s" driver "%s".',
                $name,
                (string) ($store['driver'] ?? $name),
            ));
        }

        $namespace = (string) ($store['namespace'] ?? ('foundation:' . $name));

        return match ($driver) {
            CacheDriver::APCU => Cache::apcu($namespace),
            CacheDriver::FILE => Cache::file($namespace, isset($store['dir']) && is_string($store['dir']) ? $store['dir'] : null),
            CacheDriver::LOCAL => Cache::local($namespace, isset($store['dir']) && is_string($store['dir']) ? $store['dir'] : null),
            CacheDriver::MEMORY => Cache::memory($namespace),
        };
    }
}
