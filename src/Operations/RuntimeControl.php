<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheLayerFactory;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\UID\Id;

final readonly class RuntimeControl
{
    public function __construct(private Application $application) {}

    public function changed(string $scope, ?string $name, string $baseline): bool
    {
        return !hash_equals($baseline, $this->token($scope, $name));
    }

    public function signal(string $scope, ?string $name = null): string
    {
        $scope = $this->key($scope, $name);
        $token = Id::uuid7();

        $this->mutate(static function (array $state) use ($scope, $token): array {
            $state[$scope] = [
                'token' => $token,
                'signaled_at' => gmdate(DATE_ATOM),
            ];

            return $state;
        });

        return $token;
    }

    /** @return array<string,array{token:string,signaled_at:string}> */
    public function status(): array
    {
        $state = $this->read();
        $normalized = [];
        foreach ($state as $scope => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $token = $entry['token'] ?? null;
            $signaledAt = $entry['signaled_at'] ?? null;
            if (is_string($token) && $token !== '' && is_string($signaledAt) && $signaledAt !== '') {
                $normalized[$scope] = ['token' => $token, 'signaled_at' => $signaledAt];
            }
        }

        return $normalized;
    }

    public function token(string $scope, ?string $name = null): string
    {
        $entry = $this->read()[$this->key($scope, $name)] ?? null;

        return is_array($entry) && is_string($entry['token'] ?? null)
            ? $entry['token']
            : '';
    }

    private function cache(): \Infocyph\CacheLayer\Cache\CacheInterface
    {
        if (!class_exists(\Infocyph\CacheLayer\Cache\Cache::class)) {
            throw new \LogicException(
                'Cache-backed runtime control requires the cache module; run "php infbyte module:install cache".',
            );
        }

        return $this->application->make(CacheManager::class)->store($this->cacheStore());
    }

    private function cacheKey(): string
    {
        $key = $this->application->config()->get('operations.runtime_control.key', 'foundation:runtime-control');

        return is_string($key) && $key !== '' ? $key : 'foundation:runtime-control';
    }

    private function cacheStore(): ?string
    {
        $store = $this->application->config()->get('operations.runtime_control.store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    private function driver(): string
    {
        $driver = strtolower(ValueNormalizer::string(
            $this->application->config()->get('operations.runtime_control.driver', 'file'),
            'file',
        ));
        if (!in_array($driver, ['file', 'cache'], true)) {
            throw new \UnexpectedValueException('operations.runtime_control.driver must be file or cache.');
        }

        return $driver;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create runtime-control directory "%s".', $directory));
        }
    }

    private function key(string $scope, ?string $name): string
    {
        $scope = strtolower(trim($scope));
        if (!in_array($scope, ['runtime', 'worker', 'schedule'], true)) {
            throw new \InvalidArgumentException('Runtime-control scope must be runtime, worker, or schedule.');
        }
        if ($name === null || $name === '') {
            return $scope;
        }
        if (preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Runtime-control name contains unsupported characters.');
        }

        return $scope . ':' . $name;
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $mutation */
    private function mutate(callable $mutation): void
    {
        if ($this->driver() === 'cache') {
            if (!class_exists(\Infocyph\CacheLayer\Cache\Cache::class)) {
                throw new \LogicException(
                    'Cache-backed runtime control requires the cache module; run "php infbyte module:install cache".',
                );
            }

            $lock = $this->application->make(CacheLayerFactory::class)->lock();
            $handle = $lock->acquire(
                'foundation:runtime-control:' . substr(hash('sha256', $this->cacheKey()), 0, 48),
                5.0,
                15.0,
            );
            if ($handle === null) {
                throw new \RuntimeException('Unable to acquire the runtime-control update lock.');
            }

            try {
                $this->write($mutation($this->read()));
            } finally {
                $lock->release($handle);
            }

            return;
        }

        $path = $this->path();
        $this->ensureDirectory($path);
        $lockPath = $path . '.lock';
        if (is_link($lockPath)) {
            throw new \RuntimeException(sprintf('Runtime-control lock path "%s" must not be a symbolic link.', $lockPath));
        }
        $stream = fopen($lockPath, 'c+b');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open runtime-control lock "%s".', $lockPath));
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock runtime-control state "%s".', $path));
            }
            $this->write($mutation($this->read()));
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    private function path(): string
    {
        $configured = $this->application->config()->get(
            'operations.runtime_control.path',
            'storage/framework/runtime-control.json',
        );
        $configured = is_string($configured) && $configured !== ''
            ? $configured
            : 'storage/framework/runtime-control.json';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? $configured
            : $this->application->basePath(trim($configured, DIRECTORY_SEPARATOR));
    }

    /** @return array<string,mixed> */
    private function read(): array
    {
        if ($this->driver() === 'cache') {
            return ValueNormalizer::associativeArray($this->cache()->get($this->cacheKey(), []));
        }

        $path = $this->path();
        if (!is_file($path)) {
            return [];
        }
        $contents = file_get_contents($path);
        if (!is_string($contents) || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Runtime-control state is corrupt.', 0, $exception);
        }

        return ValueNormalizer::associativeArray($decoded);
    }

    /** @param array<string,mixed> $state */
    private function write(array $state): void
    {
        if ($this->driver() === 'cache') {
            if (!$this->cache()->set($this->cacheKey(), $state)) {
                throw new \RuntimeException('Cache backend rejected runtime-control state.');
            }

            return;
        }

        $path = $this->path();
        $this->ensureDirectory($path);
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to stage runtime-control state "%s".', $temporary));
        }

        try {
            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to activate runtime-control state "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
