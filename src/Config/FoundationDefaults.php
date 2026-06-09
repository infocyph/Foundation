<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class FoundationDefaults
{
    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return [
            'app' => [
                'base_path' => getcwd() ?: '.',
                'container_alias' => null,
                'debug' => false,
                'env' => 'local',
                'name' => 'Foundation Application',
            ],
            'cache' => [
                'default' => 'memory',
                'prefix' => 'foundation:',
                'stores' => [
                    'file' => [
                        'driver' => 'file',
                        'namespace' => 'foundation-file',
                    ],
                    'local' => [
                        'driver' => 'local',
                        'namespace' => 'foundation-local',
                    ],
                    'memory' => [
                        'driver' => 'memory',
                        'namespace' => 'foundation-memory',
                    ],
                ],
            ],
            'database' => [
                'default' => null,
                'connections' => [],
            ],
            'notifications' => [
                'default_channel' => 'log',
                'channels' => [],
            ],
            'paths' => [
                'cache' => 'storage/cache',
                'config' => 'config',
                'logs' => 'storage/logs',
                'storage' => 'storage',
            ],
            'providers' => [],
            'router' => [
                'cache' => null,
                'middleware' => [],
            ],
            'security' => [
                'signed_urls' => true,
            ],
            'validation' => [
                'fail_fast' => true,
            ],
        ];
    }
}
