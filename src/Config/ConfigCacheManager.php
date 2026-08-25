<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\ServiceProviderInterface;

final readonly class ConfigCacheManager
{
    public function __construct(private Application $application) {}

    public function clear(string $path = 'bootstrap/cache/config'): bool
    {
        $directory = $this->path($path);
        if (!is_dir($directory)) {
            return false;
        }

        $files = glob($directory . DIRECTORY_SEPARATOR . '*.php');
        if ($files === false || $files === []) {
            return false;
        }
        if (!is_writable($directory)) {
            throw new \RuntimeException(sprintf('Config cache directory "%s" is not writable.', $directory));
        }

        foreach ($files as $file) {
            if (!unlink($file)) {
                throw new \RuntimeException(sprintf('Unable to remove config cache file "%s".', $file));
            }
        }
        if (!rmdir($directory) && is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to remove config cache directory "%s".', $directory));
        }

        return true;
    }

    public function path(string $path = 'bootstrap/cache/config'): string
    {
        $path = $path !== '' ? $path : 'bootstrap/cache/config';

        return $this->absolute($path)
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    public function write(string $path = 'bootstrap/cache/config', ?string $type = null): string
    {
        $directory = $this->path($path);
        $loader = new ConfigLoader();
        $config = $loader->load([
            'base_path' => $this->application->basePath(),
            '_config_cache' => false,
        ]);
        $compiled = $config->all();
        $compiled['providers'] = $this->compiledProviders(
            is_array($compiled['providers'] ?? null) ? $compiled['providers'] : [],
            new ProviderFileLoader($this->application->paths())->groups(),
        );
        ConfigExportValidator::assertExportable($compiled);

        $staging = $directory . '.building.' . bin2hex(random_bytes(6));
        $backup = $directory . '.previous.' . bin2hex(random_bytes(6));

        try {
            $cacheType = $loader->writeCache(
                new ConfigRepository($compiled),
                $staging,
                $type,
                $this->application->basePath(),
            );
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
     * @param array<array-key, mixed> $configured
     * @param array{common:list<class-string<ServiceProviderInterface>>,web:list<class-string<ServiceProviderInterface>>,cli:list<class-string<ServiceProviderInterface>>,worker:list<class-string<ServiceProviderInterface>>,scheduler:list<class-string<ServiceProviderInterface>>} $fromFile
     * @return array{common:list<class-string<ServiceProviderInterface>>,web:list<class-string<ServiceProviderInterface>>,cli:list<class-string<ServiceProviderInterface>>,worker:list<class-string<ServiceProviderInterface>>,scheduler:list<class-string<ServiceProviderInterface>>}
     */
    private function compiledProviders(array $configured, array $fromFile): array
    {
        $compiled = [];
        foreach (['common', 'web', 'cli', 'worker', 'scheduler'] as $group) {
            $groupProviders = [];
            $providers = $configured[$group] ?? [];
            if (!is_array($providers)) {
                throw new \RuntimeException(sprintf(
                    'Configuration value "providers.%s" must be an array of service-provider class strings.',
                    $group,
                ));
            }

            foreach ([...$providers, ...$fromFile[$group]] as $index => $provider) {
                $path = sprintf('providers.%s.%s', $group, (string) $index);
                if (!is_string($provider) || trim($provider) === '') {
                    throw new \RuntimeException(sprintf(
                        'Configuration value "%s" must be a non-empty service-provider class string.',
                        $path,
                    ));
                }
                if (!class_exists($provider) || !is_subclass_of($provider, ServiceProviderInterface::class)) {
                    throw new \RuntimeException(sprintf(
                        'Configuration value "%s" must reference a loadable %s implementation; "%s" is invalid.',
                        $path,
                        ServiceProviderInterface::class,
                        $provider,
                    ));
                }

                /** @var class-string<ServiceProviderInterface> $provider */
                $groupProviders[$provider] = $provider;
            }
            $compiled[$group] = array_values($groupProviders);
        }

        return $compiled;
    }

    private function publish(string $directory, string $staging, string $backup): void
    {
        $parent = dirname($directory);
        if (!is_dir($parent) && !mkdir($parent, 0775, true) && !is_dir($parent)) {
            throw new \RuntimeException(sprintf('Unable to create config cache parent "%s".', $parent));
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
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
