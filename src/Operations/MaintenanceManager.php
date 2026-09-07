<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Psr\Container\ContainerInterface;

final readonly class MaintenanceManager
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
        private ContainerInterface $container,
    ) {}

    public function disable(): bool
    {
        if ($this->driver() === 'cache') {
            return $this->cache()->delete($this->cacheKey());
        }

        $path = $this->path();
        if (!is_file($path)) {
            return false;
        }
        if (!unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to disable maintenance state at "%s".', $path));
        }

        return true;
    }

    /** @return array{enabled:bool,enabled_at:?string,retry_after:?int,message:?string,driver:string} */
    public function enable(?int $retryAfter = null, ?string $message = null): array
    {
        if ($retryAfter !== null && $retryAfter < 1) {
            throw new \InvalidArgumentException('Maintenance retry-after must be positive when provided.');
        }

        $state = [
            'enabled' => true,
            'enabled_at' => gmdate(DATE_ATOM),
            'retry_after' => $retryAfter,
            'message' => $message,
        ];
        $this->write($state);

        return [...$state, 'driver' => $this->driver()];
    }

    /** @return array{enabled:bool,enabled_at:?string,retry_after:?int,message:?string,driver:string} */
    public function status(): array
    {
        $state = $this->read();

        return [
            'enabled' => ($state['enabled'] ?? false) === true,
            'enabled_at' => is_string($state['enabled_at'] ?? null) ? $state['enabled_at'] : null,
            'retry_after' => is_int($state['retry_after'] ?? null) ? $state['retry_after'] : null,
            'message' => is_string($state['message'] ?? null) ? $state['message'] : null,
            'driver' => $this->driver(),
        ];
    }

    private function cache(): \Infocyph\CacheLayer\Cache\CacheInterface
    {
        if (!class_exists(\Infocyph\CacheLayer\Cache\Cache::class)) {
            throw new \LogicException(
                'Cache-backed maintenance mode requires the cache module; run "php infbyte module:install cache".',
            );
        }
        if (!$this->container->has(CacheManager::class)) {
            throw new \LogicException(
                'Cache-backed maintenance mode requires the Foundation cache module to be registered.',
            );
        }

        $manager = $this->container->get(CacheManager::class);
        if (!$manager instanceof CacheManager) {
            throw new \LogicException('Foundation cache module did not resolve CacheManager.');
        }

        $store = $this->config->get('operations.maintenance.store');

        return $manager->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function cacheKey(): string
    {
        $key = $this->config->get('operations.maintenance.key', 'foundation:maintenance');

        return is_string($key) && $key !== '' ? $key : 'foundation:maintenance';
    }

    private function driver(): string
    {
        $driver = strtolower(ValueNormalizer::string(
            $this->config->get('operations.maintenance.driver', 'file'),
            'file',
        ));
        if (!in_array($driver, ['file', 'cache'], true)) {
            throw new \UnexpectedValueException('operations.maintenance.driver must be file or cache.');
        }

        return $driver;
    }

    private function path(): string
    {
        $configured = $this->config->get(
            'operations.maintenance.path',
            'storage/framework/maintenance.json',
        );
        $configured = is_string($configured) && $configured !== '' ? $configured : 'storage/framework/maintenance.json';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? $configured
            : $this->paths->base(trim($configured, DIRECTORY_SEPARATOR));
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
            $decoded = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Maintenance state is corrupt.', 0, $exception);
        }

        return ValueNormalizer::associativeArray($decoded);
    }

    /** @param array<string,mixed> $state */
    private function write(array $state): void
    {
        if ($this->driver() === 'cache') {
            if (!$this->cache()->set($this->cacheKey(), $state)) {
                throw new \RuntimeException('Cache backend rejected maintenance state.');
            }

            return;
        }

        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create maintenance directory "%s".', $directory));
        }
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to stage maintenance state "%s".', $temporary));
        }

        try {
            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to activate maintenance state "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
