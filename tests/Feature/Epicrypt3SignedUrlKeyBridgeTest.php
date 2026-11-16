<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Routing\SignedUrlKeyResolver;
use Infocyph\Webrick\Exceptions\HttpException;
use Infocyph\Webrick\Middleware\VerifySignedUrlMiddleware;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
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

it('keeps the previous signing key readable after active key rotation', function (): void {
    $oldEnvironment = 'FOUNDATION_TEST_SIGNED_URL_ROTATION_OLD';
    $newEnvironment = 'FOUNDATION_TEST_SIGNED_URL_ROTATION_NEW';
    $_ENV[$oldEnvironment] = str_repeat('o', 32);
    $_ENV[$newEnvironment] = str_repeat('n', 32);

    try {
        $old = new SignedUrlKeyResolver(new ConfigRepository([
            'router' => ['signed_urls' => ['keys' => [
                ['id' => 'old', 'environment' => $oldEnvironment, 'status' => 'active'],
            ]]],
        ]))->resolve();
        $rotated = new SignedUrlKeyResolver(new ConfigRepository([
            'router' => ['signed_urls' => ['keys' => [
                ['id' => 'new', 'environment' => $newEnvironment, 'status' => 'active'],
                ['id' => 'old', 'environment' => $oldEnvironment, 'status' => 'fallback'],
            ]]],
        ]))->resolve();

        expect($old)->toBeInstanceOf(SignedUrlConfig::class)
            ->and($rotated)->toBeInstanceOf(SignedUrlConfig::class)
            ->and($rotated?->generationKey)->not->toBe($old?->generationKey)
            ->and($rotated?->verificationKeys)->toContain($old?->generationKey);
    } finally {
        unset($_ENV[$oldEnvironment], $_ENV[$newEnvironment]);
    }
});

it('verifies rotated signed URLs with native Webrick and rejects tampering and expiration', function (): void {
    $previousEnvironment = 'FOUNDATION_TEST_WEBRICK_ROTATED_SIGNED_URL_PREVIOUS';
    $activeEnvironment = 'FOUNDATION_TEST_WEBRICK_ROTATED_SIGNED_URL_ACTIVE';
    $_ENV[$previousEnvironment] = str_repeat('p', 32);
    $_ENV[$activeEnvironment] = str_repeat('a', 32);

    try {
        $previous = new SignedUrlKeyResolver(new ConfigRepository([
            'router' => ['signed_urls' => ['keys' => [
                ['id' => 'previous', 'environment' => $previousEnvironment, 'status' => 'active'],
            ]]],
        ]))->resolve();
        $rotated = new SignedUrlKeyResolver(new ConfigRepository([
            'router' => ['signed_urls' => ['keys' => [
                ['id' => 'active', 'environment' => $activeEnvironment, 'status' => 'active'],
                ['id' => 'previous', 'environment' => $previousEnvironment, 'status' => 'fallback'],
            ]]],
        ]))->resolve();
        $previous = foundationFixtureObject($previous, SignedUrlConfig::class);
        $rotated = foundationFixtureObject($rotated, SignedUrlConfig::class);

        $aliases = ['download' => ['/download/{id}', null]];
        $previousGenerator = new UrlGenerator('', $aliases, signedConfig: $previous);
        $activeGenerator = new UrlGenerator('', $aliases, signedConfig: $rotated);
        $verifier = new VerifySignedUrlMiddleware($rotated);
        $next = static fn(Request $request): Response => Response::plaintext($request->getMethod());

        foreach ([$previousGenerator, $activeGenerator] as $generator) {
            $signed = $generator->temporary(
                'download',
                ['id' => 42],
                ['mode' => 'inline'],
                ttl: 60,
                absolute: false,
            );
            $path = parse_url($signed, PHP_URL_PATH);
            $queryString = parse_url($signed, PHP_URL_QUERY);
            if (!is_string($path) || !is_string($queryString)) {
                throw new RuntimeException('Unable to parse generated signed URL.');
            }
            parse_str($queryString, $query);
            foreach ($query as $key => $value) {
                if (!is_string($key) || !is_string($value)) {
                    throw new \UnexpectedValueException('Generated signed URL query must contain scalar string parameters.');
                }
            }
            /** @var array<string,string> $query */
            $request = Request::fake(query: $query, uri: 'https://signed.example.test' . $path);
            expect((string) $verifier($request, $next)->getBody())->toBe('GET');

            expect(fn(): Response => $verifier(
                Request::fake(
                    query: [...$query, 'mode' => 'download'],
                    uri: 'https://signed.example.test' . $path,
                ),
                $next,
            ))->toThrow(HttpException::class, 'Invalid signature');

            expect(fn(): Response => $verifier(
                Request::fake(query: [...$query, '_exp' => '1'], uri: 'https://signed.example.test' . $path),
                $next,
            ))->toThrow(HttpException::class, 'URL expired');
        }

        expect(fn(): Response => $verifier(
            Request::fake(uri: 'https://signed.example.test/download/42'),
            $next,
        ))->toThrow(HttpException::class, 'Missing signature');
    } finally {
        unset($_ENV[$previousEnvironment], $_ENV[$activeEnvironment]);
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
