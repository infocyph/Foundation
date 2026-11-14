<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LayeredLazyFileConfig;

final class ConfigRepository extends Config
{
    private ?LayeredLazyFileConfig $lazyConfig = null;

    /** @var array<array-key, true> */
    private array $lazyNamespaces = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [], private readonly bool $compiled = false)
    {
        if ($items !== []) {
            $this->items = $items;
        }
    }

    /**
     * @param array<string, mixed> $fallback
     * @param array<string, mixed> $overrides
     * @param list<string> $namespaces
     */
    public static function fromLazyFiles(
        string $directory,
        ?string $cacheDirectory,
        array $fallback,
        array $overrides,
        array $namespaces,
        bool $compiled = false,
    ): self {
        $repository = new self(compiled: $compiled);
        $repository->lazyConfig = new LayeredLazyFileConfig(
            directory: $directory,
            namespaceCacheDirectory: $cacheDirectory,
            fallback: $fallback,
            overrides: $overrides,
            namespaces: $namespaces,
        );

        foreach ($namespaces as $namespace) {
            if ($namespace !== '') {
                $repository->lazyNamespaces[$namespace] = true;
            }
        }

        return $repository;
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function all(): array
    {
        $this->materializeLazyConfig();

        $items = [];
        foreach (parent::all() as $key => $value) {
            if (is_string($key)) {
                $items[$key] = $value;
            }
        }

        return $items;
    }

    public function cacheDirectory(): ?string
    {
        return $this->lazyConfig?->namespaceCacheDirectory();
    }

    #[\Override]
    public function changed(string $snapshot = 'default'): bool
    {
        $this->materializeLazyConfig();

        return parent::changed($snapshot);
    }

    public function clearLazyCache(): void
    {
        $this->lazyConfig?->clearNamespaceCache();
    }

    public function env(?string $default = null): ?string
    {
        $env = $this->getString('app.env', $default);

        return is_string($env) && $env !== ''
            ? $env
            : $default;
    }

    #[\Override]
    public function exportCache(string $path): bool
    {
        $this->materializeLazyConfig();

        return parent::exportCache($path);
    }

    #[\Override]
    public function get(string|int|array|null $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->all() : parent::get($key, $default);
    }

    public function isCompiled(): bool
    {
        return $this->compiled;
    }

    public function isEnvironment(string $environment): bool
    {
        $current = $this->env();

        return $current !== null && strcasecmp($current, $environment) === 0;
    }

    public function isProduction(): bool
    {
        return $this->isEnvironment('production');
    }

    /** @return list<string> */
    public function lazyNamespaces(): array
    {
        return array_map(static fn(int|string $namespace): string => (string) $namespace, array_keys($this->lazyNamespaces));
    }

    #[\Override]
    public function snapshot(string $name = 'default'): bool
    {
        $this->materializeLazyConfig();

        return parent::snapshot($name);
    }

    public function warmLazyCache(): void
    {
        $this->lazyConfig?->warmNamespaceCache(array_keys($this->lazyNamespaces));
    }

    #[\Override]
    protected function assertWritable(): void
    {
        parent::assertWritable();
        $this->materializeLazyConfig();
    }

    #[\Override]
    protected function resolveRawValue(int|string $key): mixed
    {
        if ($this->lazyConfig === null) {
            return parent::resolveRawValue($key);
        }

        return $this->lazyConfig->get((string) $key, $this->missingValueMarker());
    }

    private function materializeLazyConfig(): void
    {
        if ($this->lazyConfig === null) {
            return;
        }

        $this->items = $this->lazyConfig->all();
        $this->lazyConfig = null;
        $this->lazyNamespaces = [];
        $this->flushReadCache();
    }
}
