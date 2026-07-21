<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Array\DotNotation;
use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LazyFileConfig;

final class ConfigRepository extends Config
{
    /**
     * @var array<string, mixed>
     */
    private array $fallback = [];

    private ?string $lazyDirectory = null;

    private ?LazyFileConfig $lazyFiles = null;

    /**
     * @var array<string, true>
     */
    private array $lazyNamespaces = [];

    /**
     * @var array<string, mixed>
     */
    private array $overrides = [];

    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
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
    ): self {
        $repository = new self();
        $repository->lazyFiles = new LazyFileConfig($directory, namespaceCacheDirectory: $cacheDirectory);
        $repository->lazyDirectory = $directory;
        $repository->fallback = $fallback;
        $repository->overrides = $overrides;

        foreach ($namespaces as $namespace) {
            if ($namespace !== '') {
                $repository->lazyNamespaces[$namespace] = true;
            }
        }

        return $repository;
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function all(): array
    {
        $this->materializeLazyNamespaces();

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
        return $this->lazyFiles?->namespaceCacheDirectory();
    }

    public function clearLazyCache(): void
    {
        $this->lazyFiles?->flushNamespaceCache();
    }

    public function env(?string $default = null): ?string
    {
        $env = $this->getString('app.env', $default);

        return is_string($env) && $env !== ''
            ? $env
            : $default;
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

    /**
     * @return list<string>
     */
    public function lazyNamespaces(): array
    {
        return array_keys($this->lazyNamespaces);
    }

    public function warmLazyCache(): void
    {
        $this->lazyFiles?->warmNamespaceCache(array_keys($this->lazyNamespaces));
    }

    #[\Override]
    protected function resolveRawValue(int|string $key): mixed
    {
        if ($this->lazyFiles === null || !is_string($key)) {
            return parent::resolveRawValue($key);
        }

        if (!$this->readCacheEnabled) {
            return $this->resolveLazyValue($key);
        }

        $cacheKey = $this->valueCacheKey($key);
        if (array_key_exists($cacheKey, $this->resolvedValueCache)) {
            return $this->resolvedValueCache[$cacheKey];
        }

        return $this->resolvedValueCache[$cacheKey] = $this->resolveLazyValue($key);
    }

    private function lazyValue(string $key, object $missing): mixed
    {
        $lazyFiles = $this->lazyFiles;
        if ($lazyFiles === null) {
            return $missing;
        }

        try {
            return $lazyFiles->get($key, $missing);
        } catch (\UnexpectedValueException) {
            // An incomplete cache must never prevent a boot from source files.
            $this->lazyFiles = new LazyFileConfig($this->lazyDirectory ?? '');

            return $this->lazyFiles->get($key, $missing);
        }
    }

    private function materializeLazyNamespaces(): void
    {
        if ($this->lazyFiles === null) {
            return;
        }

        $namespaces = $this->lazyNamespaces
            + array_fill_keys(array_keys($this->fallback), true)
            + array_fill_keys(array_keys($this->overrides), true);

        foreach (array_keys($namespaces) as $namespace) {
            $value = $this->resolveLazyNamespace($namespace);
            if ($value !== $this->missingValueMarker()) {
                $this->items[$namespace] = $value;
            }
        }

        $this->lazyFiles = null;
        $this->lazyDirectory = null;
        $this->fallback = [];
        $this->overrides = [];
        $this->lazyNamespaces = [];
        $this->flushReadCache();
    }

    private function resolveLazyNamespace(string $namespace): mixed
    {
        $missing = $this->missingValueMarker();
        $source = $this->lazyValue($namespace, $missing);

        if (
            !array_key_exists($namespace, $this->fallback)
            && !array_key_exists($namespace, $this->overrides)
            && $source === $missing
        ) {
            return $missing;
        }

        $layers = [];
        if (array_key_exists($namespace, $this->fallback)) {
            $layers[] = [$namespace => $this->fallback[$namespace]];
        }

        if ($source !== $missing) {
            $layers[] = [$namespace => $source];
        }

        if (array_key_exists($namespace, $this->overrides)) {
            $layers[] = [$namespace => $this->overrides[$namespace]];
        }

        $merged = ConfigMerger::mergeMany($layers);

        return array_key_exists($namespace, $merged) ? $merged[$namespace] : $missing;
    }

    private function resolveLazyValue(string $key): mixed
    {
        if (str_contains($key, '*') || str_contains($key, '{') || str_contains($key, '\\')) {
            [$namespace] = explode('.', $key, 2);
            $value = $this->resolveLazyNamespace($namespace);

            if ($value !== $this->missingValueMarker()) {
                $this->items[$namespace] = $value;
            }

            return parent::resolveRawValue($key);
        }

        $parts = explode('.', $key, 2);
        $namespace = $parts[0];
        $rest = $parts[1] ?? null;
        if ($rest === null) {
            return $this->resolveLazyNamespace($namespace);
        }

        $missing = $this->missingValueMarker();
        $override = DotNotation::get($this->overrides, $key, $missing);
        if ($override !== $missing) {
            return $override;
        }

        $source = $this->lazyValue($key, $missing);
        if ($source !== $missing) {
            return $source;
        }

        return DotNotation::get($this->fallback, $key, $missing);
    }
}
