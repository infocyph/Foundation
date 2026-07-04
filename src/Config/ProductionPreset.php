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
                'cachelayer' => [
                    'store' => 'auth',
                ],
                'drivers' => [
                    'cache' => 'cachelayer',
                    'mfa' => 'otp',
                    'notifications' => 'talkingbytes',
                    'passkey' => 'disabled',
                    'passwords' => 'epicrypt',
                    'storage' => 'dblayer',
                    'tokens' => 'epicrypt',
                ],
                'token_secret' => 'replace-with-a-production-token-secret',
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
