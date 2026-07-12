<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Data;

use Infocyph\ArrayKit\ArrayKit;
use Infocyph\ArrayKit\Collection\Collection;
use Infocyph\ArrayKit\Collection\HookedCollection;
use Infocyph\ArrayKit\Collection\LazyCollection;
use Infocyph\ArrayKit\Collection\Pipeline;
use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\ArrayKit\DTO\GenericDTO;
use Infocyph\ArrayKit\Facade\ModuleProxy;
use Infocyph\Foundation\Filesystem\PathManager;

final readonly class DataManager
{
    public function __construct(
        private PathManager $paths,
    ) {}

    public function collection(mixed $data = []): Collection
    {
        return ArrayKit::collection($data);
    }

    /**
     * @param array<array-key, mixed> $items
     */
    public function config(array $items = []): Config
    {
        return ArrayKit::config($items);
    }

    public function dot(): ModuleProxy
    {
        return ArrayKit::dot();
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<string, string> $mapping
     */
    public function dto(
        array $values = [],
        ?string $class = null,
        array $mapping = [],
        bool $nested = false,
        bool $coerce = false,
    ): object {
        $class ??= GenericDTO::class;

        if (!class_exists($class)) {
            throw new \RuntimeException(sprintf('DTO class "%s" was not found.', $class));
        }

        $dto = new $class();

        if ($nested && method_exists($dto, 'hydrateNested')) {
            return $this->resolveDtoResult(
                $dto->hydrateNested($values, $mapping, $coerce),
                $class,
            );
        }

        if (method_exists($dto, 'hydrate')) {
            return $this->resolveDtoResult(
                $dto->hydrate($values, $mapping, $coerce),
                $class,
            );
        }

        if (method_exists($dto, 'fromArray')) {
            return $this->resolveDtoResult($dto->fromArray($values), $class);
        }

        throw new \RuntimeException(sprintf(
            'DTO class "%s" must provide hydrate(), hydrateNested(), or fromArray().',
            $class,
        ));
    }

    public function env(?string $key = null, mixed $default = null): mixed
    {
        return Environment::get($key, $default);
    }

    public function helper(): ModuleProxy
    {
        return ArrayKit::helper();
    }

    public function hookedCollection(mixed $data = []): HookedCollection
    {
        return ArrayKit::hookedCollection($data);
    }

    /**
     * @return LazyCollection<array-key, mixed>
     */
    public function lazyCollection(mixed $data = []): LazyCollection
    {
        return ArrayKit::lazyCollection($data);
    }

    /**
     * @param array<array-key, mixed> $items
     */
    public function lazyConfig(
        ?string $directory = null,
        string $extension = 'php',
        array $items = [],
        ?string $namespaceCacheDirectory = null,
    ): LazyFileConfig {
        return ArrayKit::lazyConfig(
            directory: $directory ?? $this->paths->config(),
            extension: $extension,
            items: $items,
            namespaceCacheDirectory: $namespaceCacheDirectory ?? $this->paths->cache('config'),
        );
    }

    public function multi(): ModuleProxy
    {
        return ArrayKit::multi();
    }

    public function pipeline(mixed $data): Pipeline
    {
        return ArrayKit::pipeline($data);
    }

    public function single(): ModuleProxy
    {
        return ArrayKit::single();
    }

    private function resolveDtoResult(mixed $dto, string $class): object
    {
        if (is_object($dto)) {
            return $dto;
        }

        throw new \RuntimeException(sprintf('DTO class "%s" must return an object instance after hydration.', $class));
    }
}
