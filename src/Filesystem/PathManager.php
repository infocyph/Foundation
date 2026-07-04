<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

final readonly class PathManager
{
    /**
     * @param array<string, string> $paths
     */
    public function __construct(
        private string $basePath,
        private array $paths = [],
    ) {}

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        return [
            'base' => $this->base(),
            'app' => $this->app(),
            'bootstrap' => $this->bootstrap(),
            'config' => $this->config(),
            'database' => $this->database(),
            'public' => $this->public(),
            'resources' => $this->resources(),
            'routes' => $this->routes(),
            'storage' => $this->storage(),
            'cache' => $this->cache(),
            'logs' => $this->logs(),
            'sessions' => $this->sessions(),
            'uploads' => $this->uploads(),
            'providers' => $this->providersFile(),
        ];
    }

    public function app(string $path = ''): string
    {
        return $this->path('app', $path);
    }

    public function base(string $path = ''): string
    {
        return $this->join($this->basePath, $path);
    }

    public function bootstrap(string $path = ''): string
    {
        return $this->path('bootstrap', $path);
    }

    public function cache(string $path = ''): string
    {
        return $this->path('cache', $path);
    }

    public function config(string $path = ''): string
    {
        return $this->path('config', $path);
    }

    public function database(string $path = ''): string
    {
        return $this->path('database', $path);
    }

    public function ensureRuntimeDirectories(int $mode = 0775): void
    {
        foreach ($this->runtimeDirectories() as $directory) {
            if (is_dir($directory)) {
                continue;
            }

            if (!mkdir($directory, $mode, true) && !is_dir($directory)) {
                throw new \RuntimeException(sprintf('Unable to create runtime directory "%s".', $directory));
            }
        }
    }

    public function logs(string $path = ''): string
    {
        return $this->path('logs', $path);
    }

    public function providersFile(): string
    {
        return $this->resolve($this->paths['providers'] ?? 'bootstrap/providers.php');
    }

    public function public(string $path = ''): string
    {
        return $this->path('public', $path);
    }

    public function resources(string $path = ''): string
    {
        return $this->path('resources', $path);
    }

    public function routes(string $path = ''): string
    {
        return $this->path('routes', $path);
    }

    /**
     * @return list<string>
     */
    public function runtimeDirectories(): array
    {
        return [
            $this->storage(),
            $this->cache(),
            $this->logs(),
            $this->sessions(),
            $this->uploads(),
        ];
    }

    public function sessions(string $path = ''): string
    {
        return $this->path('sessions', $path);
    }

    public function storage(string $path = ''): string
    {
        return $this->path('storage', $path);
    }

    public function uploads(string $path = ''): string
    {
        return $this->path('uploads', $path);
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function join(string $base, string $path = ''): string
    {
        $base = rtrim($base, DIRECTORY_SEPARATOR);

        if ($path === '') {
            return $base;
        }

        return $base . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function path(string $key, string $path = ''): string
    {
        return $this->join(
            $this->resolve($this->paths[$key] ?? $key),
            $path,
        );
    }

    private function resolve(string $path): string
    {
        if ($this->absolute($path)) {
            return rtrim($path, DIRECTORY_SEPARATOR);
        }

        return $this->join($this->basePath, $path);
    }
}
