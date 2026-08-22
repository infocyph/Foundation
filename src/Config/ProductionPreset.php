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
                'topology' => DeploymentTopology::SINGLE_NODE->value,
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
                // `local` is deliberately a single-node cache. Distributed
                // deployments must replace it with a shared backend and select
                // an atomic Redis/Valkey counter for auth lockouts.
                'default' => 'auth',
                'stores' => [
                    'auth' => [
                        'driver' => 'local',
                        'namespace' => 'foundation-auth',
                        'path' => 'storage/cache/auth',
                    ],
                ],
            ],
            'database' => [
                // Deliberately unresolved by the preset: production applications
                // must configure the actual primary connection explicitly.
                'default' => 'primary',
            ],
            'logging' => [
                'driver' => 'file',
                'level' => 'warning',
            ],
            'notifications' => [
                // Readiness fails until this names an application sender profile.
                'auth' => [
                    'sender' => 'replace-me',
                ],
            ],
        ];
    }
}
