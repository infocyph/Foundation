<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
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
            'auth' => [
                'account' => 'ulid',
                'correlation' => 'uuid7',
            ],
        ],
    ])->boot();

    $authIds = $app->make(AuthIdGeneratorInterface::class);

    expect($authIds)->toBeInstanceOf(UidAuthIdGenerator::class)
        ->and(ULID::isValid($authIds->accountId()))->toBeTrue()
        ->and(UUID::isValid($authIds->correlationId()))->toBeTrue();
});

it('uses UID-backed defaults for auth identifiers', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => [],
        ],
    ]);

    $ids = $app->make(AuthIdGeneratorInterface::class);

    expect($ids)->toBeInstanceOf(UidAuthIdGenerator::class)
        ->and(UUID::isValid($ids->accountId()))->toBeTrue()
        ->and(ULID::isValid($ids->correlationId()))->toBeTrue();
});

it('rejects unsupported auth identifier strategies instead of falling back silently', function (): void {
    $app = Foundation::web([
        '_config_cache' => false,
        'router' => [
            'cache' => false,
            'files' => [],
        ],
        'ids' => [
            'auth' => [
                'account' => 'random',
            ],
        ],
    ]);

    $ids = $app->make(AuthIdGeneratorInterface::class);

    expect(fn(): string => $ids->accountId())
        ->toThrow(InvalidArgumentException::class, 'use uuid7 or ulid');
});
