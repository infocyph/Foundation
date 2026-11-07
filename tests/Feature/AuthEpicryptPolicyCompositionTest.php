<?php

declare(strict_types=1);

use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Foundation\Auth\Internal\EpicryptTokenPolicyResolver;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Exception\ConfigurationException;

it('validates native Epicrypt JWT policy directly from immutable build configuration', function (): void {
    $policy = new EpicryptTokenPolicyResolver(new ConfigRepository([
        'security' => [
            'jwt' => [
                'algorithm' => 'HS512',
                'issuer' => 'https://identity.example.test',
                'audience' => 'foundation-test',
                'leeway_seconds' => 15,
                'maximum_lifetime_seconds' => 3600,
            ],
        ],
    ]));

    expect($policy->algorithm())->toBe(SymmetricJwtAlgorithm::HS512)
        ->and($policy->minimumKeyBytes())->toBe(64)
        ->and($policy->issuer())->toBe('https://identity.example.test')
        ->and($policy->audience())->toBe('foundation-test')
        ->and($policy->leewaySeconds())->toBe(15)
        ->and($policy->maximumLifetimeSeconds())->toBe(3600);
});

it('rejects malformed JWT policy without needing a mutable development container', function (): void {
    $policy = new EpicryptTokenPolicyResolver(new ConfigRepository([
        'security' => ['jwt' => ['algorithm' => 'unsupported']],
    ]));

    expect(fn() => $policy->algorithm())
        ->toThrow(ConfigurationException::class, 'security.jwt.algorithm must be HS256, HS384, or HS512.');
});
