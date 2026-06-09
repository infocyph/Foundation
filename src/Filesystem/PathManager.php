<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Pathwise\Utils\PathHelper;

final readonly class PathManager
{
    public function __construct(
        private ConfigRepository $config,
    ) {}

    public function base(string $path = ''): string
    {
        return $this->join(
            (string) $this->config->get('app.base_path', '.'),
            $path,
        );
    }

    public function cache(string $path = ''): string
    {
        return $this->segment('paths.cache', $path);
    }

    public function config(string $path = ''): string
    {
        return $this->segment('paths.config', $path);
    }

    public function logs(string $path = ''): string
    {
        return $this->segment('paths.logs', $path);
    }

    public function storage(string $path = ''): string
    {
        return $this->segment('paths.storage', $path);
    }

    private function join(string ...$segments): string
    {
        if ($segments === []) {
            return '';
        }

        $filtered = array_values(array_filter(
            $segments,
            static fn(string $segment): bool => $segment !== '',
        ));

        if ($filtered === []) {
            return '';
        }

        return PathHelper::join(...$filtered);
    }

    private function segment(string $configKey, string $path = ''): string
    {
        return $this->join(
            $this->base(),
            (string) $this->config->get($configKey, ''),
            $path,
        );
    }
}
