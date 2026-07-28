<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ProductionPreset implements FoundationPreset
{
    public function config(): array
    {
        return [
            'app' => [
                'env' => 'production',
            ],
            'auth' => [
                'drivers' => [
                    'cache' => 'cache',
                    'mfa' => 'otp',
                    'notifications' => 'talkingbytes',
                    'passkey' => 'disabled',
                    'passwords' => 'security',
                    'storage' => 'database',
                    'tokens' => 'security',
                ],
            ],
            'cache' => [
                'default' => 'auth',
                'stores' => [
                    'auth' => [
                        'driver' => 'local',
                        'namespace' => 'foundation-auth',
                    ],
                ],
            ],
            'database' => [
                'default' => 'primary',
            ],
            'notifications' => [
                'auth' => [
                    'transport' => 'replace-me',
                ],
            ],
        ];
    }
}
