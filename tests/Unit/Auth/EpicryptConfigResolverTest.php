<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Epicrypt\Password\PasswordHashOptions;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;

it('normalizes crypto-owned Epicrypt authentication configuration', function (): void {
    $application = Foundation::cli([
        '_config_cache' => false,
        'security' => [
            'password' => [
                'algorithm' => 'bcrypt',
                'cost' => 13,
            ],
            'jwt' => [
                'audience' => 'foundation-api',
                'issuer' => 'https://identity.example.test',
                'leeway_seconds' => 30,
            ],
        ],
    ]);

    $password = $application->make(PasswordHashOptions::class);

    expect($password->algorithm)->toBe(PasswordHashAlgorithm::BCRYPT)
        ->and($password->bcryptCost)->toBe(13)
        ->and($application->config()->get('security.jwt.audience'))->toBe('foundation-api')
        ->and($application->config()->get('security.jwt.issuer'))->toBe('https://identity.example.test')
        ->and($application->config()->get('security.jwt.leeway_seconds'))->toBe(30);
});

it('selects the installed security capability without exposing its package name', function (): void {
    $resolver = new AuthDriverResolver(new ConfigRepository([
        'auth' => [
            'drivers' => [
                'passwords' => 'security',
                'tokens' => 'security',
            ],
        ],
    ]));

    expect($resolver->passwords())->toBe(AuthPasswordDriver::SECURITY)
        ->and($resolver->tokens())->toBe(AuthTokenDriver::SECURITY);
});
