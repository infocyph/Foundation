<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Driver\AuthPasswordDriver;
use Infocyph\Foundation\Auth\Driver\AuthTokenDriver;
use Infocyph\Foundation\Auth\Internal\EpicryptConfigResolver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;

it('normalizes crypto-owned Epicrypt authentication configuration', function (): void {
    $application = Foundation::console([
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

    $resolver = new EpicryptConfigResolver($application);
    $password = $resolver->passwordOptions();

    expect($password)->not->toHaveKey('profile')
        ->and($password['algorithm'])->toBe(PasswordHashAlgorithm::BCRYPT)
        ->and($password['cost'])->toBe(13)
        ->and($resolver->tokenAudience())->toBe('foundation-api')
        ->and($resolver->tokenIssuer())->toBe('https://identity.example.test')
        ->and($resolver->tokenLeeway())->toBe(30);
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
