<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;

final class RouteCachePath
{
    public static function for(ConfigRepository $config): string
    {
        $basePath = $config->getString('app.base_path', getcwd() ?: '.') ?: (getcwd() ?: '.');
        $directory = rtrim($basePath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'bootstrap/cache/routes';

        return match ($config->getString('router.matcher', 'fused')) {
            'generated' => $directory . DIRECTORY_SEPARATOR . 'generated.php',
            'sharded' => $directory,
            default => $directory . DIRECTORY_SEPARATOR . 'fused.php',
        };
    }
}
