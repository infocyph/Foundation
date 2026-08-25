<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigIssue;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\OtpConfigValidator;
use Infocyph\Foundation\Config\ProductionSecurityValidator;
use Infocyph\Foundation\Diagnostics\ReadinessReport;
use Infocyph\Foundation\Foundation;

it('rejects unsafe production auth defaults secrets email transport and WebAuthn origin', function (): void {
    $app = Foundation::cli([
        '_config_cache' => false,
        'app' => [
            'base_path' => sys_get_temp_dir(),
            'env' => 'production',
            'topology' => 'single_node',
        ],
        'auth' => [
            'token_secret' => 'foundation-dev-secret',
            'drivers' => [
                'cache' => 'array',
                'mfa' => 'otp',
                'notifications' => 'talkingbytes',
                'passkey' => 'webauthn',
                'passwords' => 'security',
                'storage' => 'memory',
                'tokens' => 'security',
            ],
            'password_policy' => [
                'min_length' => 8,
                'max_length' => 1024,
            ],
            'otp' => [
                'replay' => ['store' => 'auth-state'],
            ],
            'webauthn' => [
                'rp_id' => 'example.test',
                'origin' => 'http://example.test',
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'default_counter' => 'auth-lockouts',
            'stores' => [
                'auth-state' => ['driver' => 'file'],
            ],
            'counters' => [
                'auth-lockouts' => ['driver' => 'redis'],
            ],
            'lock' => ['driver' => 'file', 'store' => 'auth-state'],
        ],
        'notifications' => [
            'auth' => ['sender' => 'auth'],
            'email' => [
                'default_sender' => 'auth',
                'senders' => [
                    'auth' => ['transport' => 'fake'],
                ],
                'transports' => [
                    'fake' => ['driver' => 'fake'],
                ],
            ],
        ],
        'security' => [
            'jwt' => [
                'algorithm' => 'HS256',
                'issuer' => 'foundation.test',
                'audience' => 'foundation-clients',
                'maximum_lifetime_seconds' => 3600,
                'leeway_seconds' => 30,
            ],
        ],
    ]);

    $issues = [
        ...new ConfigValidator($app->config())->validateForProduction()->toArray()['issues'],
        ...array_map(static fn(ConfigIssue $issue): array => [
            'message' => $issue->message,
            'key' => $issue->key,
            'severity' => $issue->severity,
        ], new ProductionSecurityValidator($app->config())->validate()),
    ];
    $keys = array_column($issues, 'key');
    $messages = array_column($issues, 'message');

    expect($keys)->toContain(
        'auth.drivers.storage',
        'auth.token_secret',
        'auth.webauthn.origin',
        'notifications.auth.sender',
        'auth.password_policy.min_length',
        'auth.drivers.cache',
    )->and(implode('; ', $messages))->toContain('development placeholder');
});

it('rejects host-local auth persistence cache coordination and OTP replay in distributed production', function (): void {
    $app = Foundation::cli([
        '_config_cache' => false,
        'app' => [
            'base_path' => sys_get_temp_dir(),
            'env' => 'production',
            'topology' => 'distributed',
        ],
        'auth' => [
            'drivers' => [
                'cache' => 'cache',
                'mfa' => 'otp',
                'storage' => 'database',
            ],
            'otp' => [
                'replay' => ['store' => 'auth-state'],
            ],
        ],
        'database' => [
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'default_counter' => 'auth-lockouts',
            'stores' => [
                'auth-state' => ['driver' => 'file'],
            ],
            'counters' => [
                'auth-lockouts' => ['driver' => 'redis'],
            ],
            'lock' => ['driver' => 'file', 'store' => 'auth-state'],
        ],
    ]);

    $productionIssues = new ProductionSecurityValidator($app->config())->validate();
    $productionKeys = array_map(static fn(ConfigIssue $issue): string => $issue->key, $productionIssues);
    $productionMessages = array_map(static fn(ConfigIssue $issue): string => $issue->message, $productionIssues);
    $otpIssues = new OtpConfigValidator($app->config())->validate(true);

    expect($productionKeys)->toContain(
        'database.connections.primary',
        'cache.stores.auth-state',
        'cache.lock',
    )->and(implode('; ', $productionMessages))->toContain(
        'database connection "primary" is only host-visible',
        'cache store "auth-state" is only host-visible',
        'configured coordination is host-visible',
    )->and($otpIssues)->not->toBeEmpty()
        ->and($otpIssues[0]->key)->toBe('auth.otp.replay.store')
        ->and($otpIssues[0]->message)->toContain('cluster-visible');
});

it('accepts a secure single-node production posture and reports production configuration ready', function (): void {
    $app = Foundation::cli(foundationSecureProductionConfig('single_node'));

    expect(new ConfigValidator($app->config())->validateForProduction()->fails())->toBeFalse()
        ->and(new ProductionSecurityValidator($app->config())->validate())->toBe([])
        ->and(new OtpConfigValidator($app->config())->validate(true))->toBe([]);

    $report = new ReadinessReport($app)->generate();
    expect($report['checks']['configuration']['ready'])->toBeTrue()
        ->and($report['checks']['configuration']['detail'])->toBe('valid for production');
});

it('accepts cluster-visible database cache lock counter and OTP replay policy for distributed production', function (): void {
    $config = foundationSecureProductionConfig('distributed');
    $config['database']['connections']['primary'] = [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'foundation',
        'username' => 'foundation',
        'password' => 'secret',
    ];
    $config['cache']['stores']['auth-state'] = ['driver' => 'redis'];
    $config['cache']['lock'] = ['driver' => 'redis', 'store' => 'auth-state'];

    $app = Foundation::cli($config);

    expect(new ConfigValidator($app->config())->validateForProduction()->fails())->toBeFalse()
        ->and(new ProductionSecurityValidator($app->config())->validate())->toBe([])
        ->and(new OtpConfigValidator($app->config())->validate(true))->toBe([]);
});

/** @return array<string,mixed> */
function foundationSecureProductionConfig(string $topology): array
{
    return [
        '_config_cache' => false,
        'app' => [
            'base_path' => sys_get_temp_dir(),
            'env' => 'production',
            'topology' => $topology,
        ],
        'auth' => [
            'token_secret' => bin2hex(random_bytes(32)),
            'drivers' => [
                'cache' => 'cache',
                'mfa' => 'otp',
                'notifications' => 'talkingbytes',
                'passkey' => 'webauthn',
                'passwords' => 'security',
                'storage' => 'database',
                'tokens' => 'security',
            ],
            'password_policy' => [
                'min_length' => 12,
                'max_length' => 1024,
            ],
            'otp' => [
                'replay' => ['store' => 'auth-state'],
            ],
            'webauthn' => [
                'rp_id' => 'example.test',
                'origin' => 'https://example.test',
                'attestation' => 'none',
                'user_verification' => 'required',
                'resident_key' => 'preferred',
                'algorithms' => ['ES256', 'RS256'],
                'transports' => ['internal', 'hybrid'],
            ],
        ],
        'database' => [
            'default' => 'primary',
            'connections' => [
                'primary' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
        'cache' => [
            'default' => 'auth-state',
            'default_counter' => 'auth-lockouts',
            'stores' => [
                'auth-state' => ['driver' => 'file'],
            ],
            'counters' => [
                'auth-lockouts' => ['driver' => 'redis'],
            ],
            'lock' => ['driver' => 'file', 'store' => 'auth-state'],
        ],
        'notifications' => [
            'auth' => ['sender' => 'auth'],
            'email' => [
                'default_sender' => 'auth',
                'senders' => [
                    'auth' => ['transport' => 'log'],
                ],
                'transports' => [
                    'log' => ['driver' => 'log'],
                ],
            ],
        ],
        'security' => [
            'jwt' => [
                'algorithm' => 'HS256',
                'issuer' => 'foundation.test',
                'audience' => 'foundation-clients',
                'maximum_lifetime_seconds' => 3600,
                'leeway_seconds' => 30,
            ],
        ],
    ];
}
