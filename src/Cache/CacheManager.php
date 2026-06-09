<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Config\ConfigRepository;

final readonly class CacheManager
{
    public function __construct(
        private ConfigRepository $config,
        private CacheLayerFactory $factory,
    ) {}

    public function config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null || $key === '') {
            return $this->config->get('cache', []);
        }

        return $this->config->get('cache.' . $key, $default);
    }

    public function store(?string $name = null): CacheInterface
    {
        return $this->factory->make($name);
    }
}
