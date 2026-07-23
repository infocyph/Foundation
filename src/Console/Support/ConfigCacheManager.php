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
            return true;
        }

        return array_all(glob($directory . DIRECTORY_SEPARATOR . '*.php') ?: [], fn($cacheFile) => unlink($cacheFile));
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
        if (!$this->clear($directory)) {
            throw new \RuntimeException(sprintf('Unable to clear config cache directory "%s".', $directory));
        }

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
