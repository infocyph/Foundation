<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Routing\SignedUrlKeyResolver;
use Infocyph\Webrick\Router\Url\SignedUrlConfig;
use Infocyph\Webrick\Router\Url\UrlGenerator;

it('feeds one active and fallback derived key into Webrick native signed URL config', function (): void {
    $activeEnvironment = 'FOUNDATION_TEST_SIGNED_URL_ACTIVE';
    $fallbackEnvironment = 'FOUNDATION_TEST_SIGNED_URL_FALLBACK';
    $_ENV[$activeEnvironment] = str_repeat('a', 32);
    $_ENV[$fallbackEnvironment] = str_repeat('b', 32);

    try {
        $resolver = new SignedUrlKeyResolver(new ConfigRepository([
            'router' => [
                'signed_urls' => [
                    'options' => [
                        'algorithm' => 'sha3-256',
                        'payload_mode' => 'relative',
                    ],
                    'keys' => [
                        ['id' => 'current', 'environment' => $activeEnvironment, 'status' => 'active'],
                        ['id' => 'previous', 'environment' => $fallbackEnvironment, 'status' => 'fallback'],
                    ],
                ],
            ],
        ]));

        $config = $resolver->resolve();
        expect($config)->toBeInstanceOf(SignedUrlConfig::class)
            ->and($config?->generationKey)->toBeString()
            ->and(strlen((string) $config?->generationKey))->toBe(32)
            ->and($config?->verificationKeys)->toHaveCount(2)
            ->and($config?->verificationKeys[0] ?? null)->toBe($config?->generationKey)
            ->and($config?->verificationKeys[1] ?? null)->not->toBe($config?->generationKey);

        $generator = new UrlGenerator('', ['download' => ['/download/{id}', null]], signedConfig: $config);
        $signed = $generator->signed('download', ['id' => 42], ['mode' => 'inline'], ttl: 60, absolute: false);
        expect($signed)->toContain('/download/42', '_sig=', '_exp=');
    } finally {
        unset($_ENV[$activeEnvironment], $_ENV[$fallbackEnvironment]);
    }
});

it('keeps Webrick behavior options artifact-safe and resolves keys only at runtime', function (): void {
    $resolver = new SignedUrlKeyResolver(new ConfigRepository([
        'router' => [
            'signed_urls' => [
                'options' => [
                    'default_ttl' => 300,
                    'generation_key' => 'must-not-enter-artifacts',
                    'verification_keys' => ['must-not-enter-artifacts-either'],
                    'signature_param' => 'signature',
                ],
            ],
        ],
    ]));

    expect($resolver->artifactOptions())->toBe([
        'defaultTtl' => 300,
        'signatureParam' => 'signature',
    ]);
});

it('rejects raw signed URL keys in production while retaining local compatibility', function (): void {
    $production = new SignedUrlKeyResolver(new ConfigRepository([
        'app' => ['env' => 'production'],
        'router' => ['signed_urls' => ['key' => str_repeat('k', 32)]],
    ]));
    expect(fn() => $production->resolve())->toThrow(ConfigurationException::class);

    $local = new SignedUrlKeyResolver(new ConfigRepository([
        'app' => ['env' => 'local'],
        'router' => ['signed_urls' => ['key' => str_repeat('k', 32)]],
    ]));
    expect($local->resolve()?->generationKey)->toBe(str_repeat('k', 32));
});
