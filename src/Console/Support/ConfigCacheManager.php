<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigLoader;

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
        $this->clear($directory);

        $loader = new ConfigLoader();
        $config = $loader->load([
            'base_path' => $this->application->basePath(),
            '_config_cache' => $directory,
        ]);

        return $loader->writeCache($config, $directory, $type);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }
}
