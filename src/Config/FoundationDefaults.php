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
                'env_files' => ['.env', '.env.local'],
                'load_env' => true,
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
                    'critical_types' => [
                        'password_reset_requested',
                        'email_verification_requested',
                        'passwordless_login_requested',
                        'mfa_challenge_requested',
                    ],
                    'fail_silently' => false,
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
                'app' => 'app',
                'auto_create_runtime_directories' => false,
                'bootstrap' => 'bootstrap',
                'cache' => 'storage/cache',
                'config' => 'config',
                'database' => 'database',
                'logs' => 'storage/logs',
                'providers' => 'bootstrap/providers.php',
                'public' => 'public',
                'resources' => 'resources',
                'routes' => 'routes',
                'sessions' => 'storage/sessions',
                'storage' => 'storage',
                'uploads' => 'storage/uploads',
            ],
            'providers' => [],
            'router' => [
                'auto_slash_redirect' => false,
                'cache' => null,
                'expose_url_services' => false,
                'files' => [
                    'web.php',
                    'api.php',
                    'auth.php',
                ],
                'load_files' => true,
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
