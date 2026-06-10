<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class LocalPreset implements FoundationPreset
{
    public function config(): array
    {
        return [
            'app' => [
                'env' => 'local',
            ],
            'auth' => [
                'drivers' => [
                    'cache' => 'array',
                    'mfa' => 'simple',
                    'notifications' => 'collect',
                    'passkey' => 'memory',
                    'passwords' => 'native',
                    'storage' => 'memory',
                    'tokens' => 'simple',
                ],
            ],
            'cache' => [
                'default' => 'memory',
            ],
            'notifications' => [
                'auth' => [
                    'transport' => 'null',
                ],
            ],
        ];
    }
}
