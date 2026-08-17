<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Otp\OtpProvisioningService;
use Infocyph\Foundation\Auth\Support\RandomAuthIdGenerator;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Identifiers\IdentifierManager;
use Infocyph\UID\ULID;
use Infocyph\UID\UUID;

it('uses Foundation only for configured identifier policy', function (): void {
    $app = Foundation::web([
        'ids' => [
            'default' => 'nanoid',
            'nanoid' => [
                'length' => 16,
            ],
            'deterministic' => [
                'length' => 12,
                'namespace' => 'infbyte',
            ],
        ],
    ]);

    $ids = $app->make(IdentifierManager::class);
    $nanoId = $ids->generate();
    $deterministic = $ids->generate('deterministic', ['payload' => 'invoice:42']);

    expect($nanoId)->toHaveLength(16)
        ->and($deterministic)->toBe($ids->generate('deterministic', ['payload' => 'invoice:42']))
        ->and(UUID::isValid(UUID::v7()))->toBeTrue()
        ->and(ULID::isValid(ULID::generate()))->toBeTrue();
});

it('supports configured auth identifier strategies', function (): void {
    $app = Foundation::web([
        'auth' => [
            'drivers' => [
                'ids' => 'uid',
            ],
        ],
        'ids' => [
            'nanoid' => [
                'length' => 18,
            ],
            'auth' => [
                'account' => 'ulid',
                'correlation' => 'nanoid',
            ],
        ],
    ])->boot();

    $authIds = $app->make(AuthIdGeneratorInterface::class);

    expect(ULID::isValid($authIds->accountId()))->toBeTrue()
        ->and(strlen($authIds->correlationId()))->toBe(18);
});

it('preserves category prefixes for fallback auth identifiers', function (): void {
    $ids = new RandomAuthIdGenerator();

    $expectedPrefixes = [
        $ids->accountId() => 'acct_',
        $ids->auditEventId() => 'evt_',
        $ids->challengeId() => 'chl_',
        $ids->correlationId() => 'corr_',
        $ids->credentialId() => 'cred_',
        $ids->deviceId() => 'dev_',
        $ids->grantId() => 'grant_',
        $ids->permissionId() => 'perm_',
        $ids->roleId() => 'role_',
        $ids->sessionId() => 'sess_',
    ];

    foreach ($expectedPrefixes as $id => $prefix) {
        expect($id)->toStartWith($prefix)
            ->and($id)->toHaveLength(strlen($prefix) + 32);
    }
});

it('keeps identifier and otp services outside the default auth path', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => [],
        ],
    ]);

    $ids = $app->make(AuthIdGeneratorInterface::class);

    expect($ids)->toBeInstanceOf(RandomAuthIdGenerator::class)
        ->and($app->container()->has(IdentifierManager::class))->toBeFalse()
        ->and($app->container()->has(OtpProvisioningService::class))->toBeFalse();
});
