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
                'auth' => [
                    'from' => null,
                    'log' => [
                        'dailyFiles' => true,
                        'directory' => null,
                        'filenamePrefix' => 'auth',
                        'maxMessageBytes' => null,
                    ],
                    'templates' => [],
                    'transport' => 'null',
                ],
                'default_channel' => 'email',
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
                'auto_slash_redirect' => false,
                'cache' => null,
                'expose_url_services' => false,
                'matcher' => 'fused',
                'middleware' => [],
                'middleware_groups' => [],
                'signed_urls' => [
                    'default_ttl' => null,
                    'key' => null,
                    'options' => [],
                ],
                'url_base_uri' => '',
            ],
            'security' => [
                'signed_urls' => true,
            ],
            'validation' => [
                'extend' => [],
                'fail_fast' => true,
                'schemas' => [],
            ],
        ];
    }
}
