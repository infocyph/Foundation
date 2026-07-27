<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class ConfigCacheManager
{
    public function __construct(private Application $application) {}

    public function clear(string $path): bool
    {
        $directory = $this->path($path);
        if (!is_dir($directory)) {
            return false;
        }

        $cacheFiles = glob($directory . DIRECTORY_SEPARATOR . '*.php');
        if ($cacheFiles === false) {
            throw new \RuntimeException(sprintf(
                'Unable to inspect config cache directory "%s".',
                $directory,
            ));
        }
        if ($cacheFiles === []) {
            return false;
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException(sprintf(
                'Config cache directory "%s" is not writable.',
                $directory,
            ));
        }

        foreach ($cacheFiles as $cacheFile) {
            if (!unlink($cacheFile)) {
                throw new \RuntimeException(sprintf(
                    'Unable to remove config cache file "%s".',
                    $cacheFile,
                ));
            }
        }

        return true;
    }

    public function path(string $path): string
    {
        if ($path === '') {
            $path = 'bootstrap/cache/config';
        }

        return $this->absolute($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    public function write(string $path, ?string $type = null): string
    {
        $directory = $this->path($path);
        $loader = new ConfigLoader();
        $config = $loader->load([
            'base_path' => $this->application->basePath(),
            '_config_cache' => false,
        ]);
        $compiled = $config->all();
        $compiled['providers'] = $this->compiledProviders(
            ValueNormalizer::associativeArray($compiled['providers'] ?? null),
            new ProviderFileLoader($this->application->paths())->groups(),
        );

        $staging = $directory . '.building.' . bin2hex(random_bytes(6));
        $backup = $directory . '.previous.' . bin2hex(random_bytes(6));

        try {
            $cacheType = $loader->writeCache(new ConfigRepository($compiled), $staging, $type);
            $this->preserveNonCacheEntries($directory, $staging);
            $this->publish($directory, $staging, $backup);

            return $cacheType;
        } finally {
            $this->removeDirectory($staging);
            $this->removeDirectory($backup);
        }
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @param array<string, mixed> $configured
     * @param array{
     *     common:list<class-string<ServiceProviderInterface>>,
     *     web:list<class-string<ServiceProviderInterface>>,
     *     console:list<class-string<ServiceProviderInterface>>
     * } $fromFile
     * @return array{
     *     common:list<class-string<ServiceProviderInterface>>,
     *     web:list<class-string<ServiceProviderInterface>>,
     *     console:list<class-string<ServiceProviderInterface>>
     * }
     */
    private function compiledProviders(array $configured, array $fromFile): array
    {
        $compiled = [];

        foreach (['common', 'web', 'console'] as $group) {
            $providers = $configured[$group] ?? [];
            $providers = is_array($providers) ? $providers : [];
            $groupProviders = [];

            foreach ([...$providers, ...$fromFile[$group]] as $provider) {
                if (!is_string($provider)
                    || $provider === ''
                    || !class_exists($provider)
                    || !is_subclass_of($provider, ServiceProviderInterface::class)
                ) {
                    continue;
                }

                $groupProviders[$provider] = $provider;
            }

            $compiled[$group] = array_values($groupProviders);
        }

        return $compiled;
    }

    private function preserveNonCacheEntries(string $source, string $target): void
    {
        if (!is_dir($source)) {
            return;
        }

        $entries = scandir($source);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_ends_with($entry, '.php')) {
                continue;
            }

            $this->preserveNonCacheEntry($source, $target, $entry);
        }
    }

    private function preserveNonCacheEntry(string $source, string $target, string $entry): void
    {
        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $target . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($from)) {
            if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
                throw new \RuntimeException(sprintf('Unable to preserve config cache directory "%s".', $from));
            }
            $this->preserveNonCacheEntries($from, $to);

            return;
        }

        if (is_file($from) && !copy($from, $to)) {
            throw new \RuntimeException(sprintf('Unable to preserve config cache file "%s".', $from));
        }
    }

    private function publish(string $directory, string $staging, string $backup): void
    {
        $parent = dirname($directory);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new \RuntimeException(sprintf('Unable to create config cache parent "%s".', $parent));
        }
        if (!is_writable($parent)) {
            throw new \RuntimeException(sprintf('Config cache parent "%s" is not writable.', $parent));
        }

        $hadCurrent = is_dir($directory);
        if ($hadCurrent && !rename($directory, $backup)) {
            throw new \RuntimeException(sprintf('Unable to stage current config cache "%s".', $directory));
        }

        if (rename($staging, $directory)) {
            return;
        }

        if ($hadCurrent) {
            rename($backup, $directory);
        }

        throw new \RuntimeException(sprintf('Unable to publish config cache "%s".', $directory));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } elseif (is_file($path)) {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
