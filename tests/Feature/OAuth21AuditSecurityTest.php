<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

it('allows only non-secret OAuth audit metadata', function (): void {
    $capture = new OAuthAuditCapture();
    $sentinels = [
        'client_secret' => 'client-secret-sentinel',
        'code' => 'authorization-code-sentinel',
        'refresh_token' => 'refresh-token-sentinel',
        'access_token' => 'access-token-sentinel',
        'code_verifier' => 'pkce-verifier-sentinel',
        'private_key' => 'private-key-sentinel',
        'authorization' => 'Basic header-sentinel',
        'private_key_path' => '/secret/oauth/private.pem',
    ];

    $capture->recorder()->record(AuthEventType::OAUTH_INVALID_REQUEST, metadata: [
        'client_id' => 'oc_safe',
        'reason' => 'malformed_request',
        ...$sentinels,
    ]);

    expect($capture->events)->toHaveCount(1);
    $encoded = json_encode($capture->events[0]->metadata, JSON_THROW_ON_ERROR);
    expect($capture->events[0]->metadata)->toBe([
        'client_id' => 'oc_safe',
        'reason' => 'malformed_request',
    ]);
    foreach ($sentinels as $value) {
        expect($encoded)->not->toContain($value);
    }
});

it('does not expose signing key locators when readiness fails', function (): void {
    $privateLocator = '/deployment/private/oauth-signing-key.pem';
    $publicLocator = '/deployment/public/oauth-signing-key.pem';
    $config = AuthDefaults::all();
    $config['auth']['oauth']['issuer'] = 'https://issuer.example.test';
    $config['auth']['oauth']['signing']['active_key_id'] = 'active_key';
    $config['auth']['oauth']['signing']['private_key'] = $privateLocator;
    $config['auth']['oauth']['signing']['public_keys'] = [[
        'id' => 'active_key',
        'path' => $publicLocator,
        'status' => 'active',
    ]];
    $capture = new OAuthAuditCapture();
    $resolver = new OAuthSigningKeyResolver(new ConfigRepository($config), $capture->recorder());

    try {
        $resolver->resolve();
        throw new RuntimeException('Expected signing-key resolution to fail for unavailable fixture paths.');
    } catch (ConfigurationException $exception) {
        expect($exception->getMessage())
            ->not->toContain($privateLocator)
            ->not->toContain($publicLocator);
    }

    expect($capture->events)->toHaveCount(1)
        ->and($capture->events[0]->type)->toBe(AuthEventType::OAUTH_KEY_READINESS)
        ->and($capture->events[0]->metadata)->toBe(['result' => 'failure']);

    $encoded = json_encode($capture->events[0]->metadata, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($privateLocator)->not->toContain($publicLocator);
});
