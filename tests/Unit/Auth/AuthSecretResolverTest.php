<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

it('keeps the development fallback in runtime policy instead of application config', function (): void {
    $environment = 'FOUNDATION_TEST_MISSING_TOKEN_SECRET';
    unset($_ENV[$environment], $_SERVER[$environment]);
    putenv($environment);

    $config = new ConfigRepository([
        'app' => ['env' => 'local'],
        'auth' => ['token_secret_environment' => $environment],
    ]);

    expect($config->get('auth.token_secret'))->toBeNull()
        ->and((new AuthSecretResolver($config))->tokenSecret())
        ->toBe('foundation-development-token-secret-change-me-000000000000000000000000');
});

it('rejects a missing production token secret at the point of use', function (): void {
    $environment = 'FOUNDATION_TEST_MISSING_PRODUCTION_TOKEN_SECRET';
    unset($_ENV[$environment], $_SERVER[$environment]);
    putenv($environment);

    $resolver = new AuthSecretResolver(new ConfigRepository([
        'app' => ['env' => 'production'],
        'auth' => ['token_secret_environment' => $environment],
    ]));

    expect(fn(): string => $resolver->tokenSecret())
        ->toThrow(ConfigurationException::class, $environment);
});

it('resolves an explicit high entropy production token secret from its locator', function (): void {
    $environment = 'FOUNDATION_TEST_PRODUCTION_TOKEN_SECRET';
    $secret = bin2hex(random_bytes(32));
    $_ENV[$environment] = $secret;
    $_SERVER[$environment] = $secret;
    putenv($environment . '=' . $secret);

    try {
        $resolver = new AuthSecretResolver(new ConfigRepository([
            'app' => ['env' => 'production'],
            'auth' => ['token_secret_environment' => $environment],
        ]));

        expect($resolver->environmentName())->toBe($environment)
            ->and($resolver->tokenSecret())->toBe($secret);
    } finally {
        unset($_ENV[$environment], $_SERVER[$environment]);
        putenv($environment);
    }
});

it('rejects raw token secrets even when an environment locator also exists', function (): void {
    $resolver = new AuthSecretResolver(new ConfigRepository([
        'app' => ['env' => 'local'],
        'auth' => [
            'token_secret' => str_repeat('x', 64),
            'token_secret_environment' => 'AUTH_TOKEN_SECRET',
        ],
    ]));

    expect(fn(): string => $resolver->tokenSecret())
        ->toThrow(ConfigurationException::class, 'Raw auth.token_secret values are not allowed');
});
