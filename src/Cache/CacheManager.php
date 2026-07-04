<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Cache;

use Infocyph\CacheLayer\Cache\CacheInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\HasConfigSection;

final readonly class CacheManager
{
    use HasConfigSection;

    public function __construct(
        private ConfigRepository $config,
        private CacheLayerFactory $factory,
    ) {}

    public function store(?string $name = null): CacheInterface
    {
        return $this->factory->make($name);
    }

    protected function configSection(): string
    {
        return 'cache';
    }
}
