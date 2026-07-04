<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Support;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Container;

abstract readonly class AbstractContainerManager
{
    use HasConfigSection;
    use ResolvesContainerService;

    public function __construct(
        protected ConfigRepository $config,
        protected Container $container,
    ) {}

    protected function objectService(string $id, string $message): object
    {
        $service = $this->resolveContainerService($id);

        if (!is_object($service)) {
            throw new \RuntimeException($message);
        }

        return $service;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    protected function typedService(string $id, string $message): object
    {
        $service = $this->resolveContainerService($id);

        if (!$service instanceof $id) {
            throw new \RuntimeException($message);
        }

        return $service;
    }
}
