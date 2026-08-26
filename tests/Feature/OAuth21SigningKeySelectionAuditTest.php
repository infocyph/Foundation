<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Tests\Fixtures\OAuthAuditCapture;

it('audits active signing key selection failure without exposing key paths', function (): void {
    $directory = sys_get_temp_dir() . '/foundation-oauth-key-selection-' . bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create OAuth signing-key fixture directory.');
    }

    $privateLocator = $directory . '/active-private.pem';
    $publicLocator = $directory . '/fallback-public.pem';
    file_put_contents($privateLocator, 'private-key-fixture');
    file_put_contents($publicLocator, 'public-key-fixture');

    $config = AuthDefaults::all();
    $config['auth']['oauth']['issuer'] = 'https://issuer.example.test';
    $config['auth']['oauth']['signing']['active_key_id'] = 'active_key';
    $config['auth']['oauth']['signing']['private_key'] = $privateLocator;
    $config['auth']['oauth']['signing']['public_keys'] = [[
        'id' => 'fallback_key',
        'path' => $publicLocator,
        'status' => 'fallback',
    ]];
    $capture = new OAuthAuditCapture();
    $resolver = new OAuthSigningKeyResolver(new ConfigRepository($config), $capture->recorder());

    try {
        $resolver->resolve();
        throw new RuntimeException('Expected active signing-key selection to fail.');
    } catch (Throwable $exception) {
        expect($exception->getMessage())
            ->not->toContain($privateLocator)
            ->not->toContain($publicLocator);
    } finally {
        @unlink($privateLocator);
        @unlink($publicLocator);
        @rmdir($directory);
    }

    expect($capture->events)->toHaveCount(1)
        ->and($capture->events[0]->type)->toBe(AuthEventType::OAUTH_KEY_READINESS)
        ->and($capture->events[0]->metadata)->toBe(['result' => 'failure']);

    $encoded = json_encode($capture->events[0]->metadata, JSON_THROW_ON_ERROR);
    expect($encoded)->not->toContain($privateLocator)->not->toContain($publicLocator);
});
