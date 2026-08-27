<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\OAuth\Configuration\OAuthConfigValidator;
use Infocyph\Foundation\Config\AuthDefaults;
use Infocyph\Foundation\Config\ConfigRepository;

it('keeps OAuth validation dormant while OAuth is disabled', function (): void {
    $config = new ConfigRepository(AuthDefaults::all());

    expect(new OAuthConfigValidator($config)->validate(false))->toBe([])
        ->and(new OAuthConfigValidator($config)->validate(true))->toBe([]);
});

it('fails closed when enabled OAuth is missing issuer keys or database state', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth']['enabled'] = true;
    $issues = new OAuthConfigValidator(new ConfigRepository($config))->validate(false);
    $keys = array_map(static fn($issue): string => $issue->key, $issues);

    expect($keys)->toContain(
        'auth.oauth.issuer',
        'auth.oauth.signing.active_key_id',
        'auth.oauth.signing.private_key',
        'database.default',
    );
});

it('accepts the supported OAuth profile when required deployment state is configured', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth'] = array_replace_recursive($config['auth']['oauth'], [
        'enabled' => true,
        'issuer' => 'https://issuer.example.test',
        'signing' => [
            'active_key_id' => 'oauth-key-1',
            'private_key' => '/run/secrets/oauth-private.pem',
            'public_keys' => [[
                'id' => 'oauth-key-1',
                'path' => '/run/secrets/oauth-public.pem',
                'status' => 'active',
            ]],
        ],
    ]);
    $config['database'] = [
        'default' => 'auth',
        'connections' => [
            'auth' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ],
    ];
    $config['cache'] = [
        'default' => 'file',
        'stores' => [
            'file' => ['driver' => 'file', 'path' => 'storage/cache/file'],
        ],
    ];

    expect(new OAuthConfigValidator(new ConfigRepository($config))->validate(true))->toBe([]);
});

it('requires host-visible OAuth rate-limit state in production', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth'] = array_replace_recursive($config['auth']['oauth'], [
        'enabled' => true,
        'issuer' => 'https://issuer.example.test',
        'signing' => [
            'active_key_id' => 'oauth-key-1',
            'private_key' => '/run/secrets/oauth-private.pem',
            'public_keys' => [[
                'id' => 'oauth-key-1',
                'path' => '/run/secrets/oauth-public.pem',
                'status' => 'active',
            ]],
        ],
    ]);
    $config['database'] = [
        'default' => 'auth',
        'connections' => [
            'auth' => ['driver' => 'sqlite', 'database' => ':memory:'],
        ],
    ];
    $config['cache'] = [
        'default' => 'memory',
        'stores' => ['memory' => ['driver' => 'memory']],
    ];
    $issues = new OAuthConfigValidator(new ConfigRepository($config))->validate(true);
    $keys = array_map(static fn($issue): string => $issue->key, $issues);

    expect($keys)->toContain('auth.oauth.rate_limit_store');
});

it('rejects insecure issuer and protocol downgrade configuration', function (): void {
    $config = AuthDefaults::all();
    $config['auth']['oauth'] = array_replace_recursive($config['auth']['oauth'], [
        'enabled' => true,
        'issuer' => 'http://issuer.example.test',
        'grants' => ['password'],
        'pkce_methods' => ['plain'],
        'signing' => [
            'active_key_id' => 'oauth-key-1',
            'private_key' => '/run/secrets/oauth-private.pem',
            'public_keys' => [],
        ],
    ]);
    $config['database'] = [
        'default' => 'auth',
        'connections' => [
            'auth' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
            ],
        ],
    ];
    $issues = new OAuthConfigValidator(new ConfigRepository($config))->validate(true);
    $keys = array_map(static fn($issue): string => $issue->key, $issues);

    expect($keys)->toContain('auth.oauth.issuer', 'auth.oauth.grants', 'auth.oauth.pkce_methods');
});
