<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenGrant;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenInspectionStatus;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenRecord;
use Infocyph\Epicrypt\Auth\OAuth\RefreshTokenRotationStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptRefreshTokenStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthEpicryptProtocolSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('rotates the active Epicrypt OAuth refresh token exactly once and revokes the family on replay', function (): void {
    DB::purge();
    $config = new ConfigRepository([
        'database' => [
            'default' => 'oauth',
            'connections' => [
                'oauth' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ],
    ]);
    $factory = new DBLayerFactory(
        new DatabaseConnectionResolver($config),
        RuntimeStateContainer::execution(),
    );
    $tables = new AuthTables();
    $connection = $factory->connection();
    $runner = new MigrationRunner($connection, [
        new AuthOAuthRevisionSchema($tables),
        new AuthOAuthEpicryptProtocolSchema($tables),
    ]);
    $store = new DBLayerEpicryptRefreshTokenStore($factory, $tables);
    $familyId = str_repeat('F', 43);

    $grant = new RefreshTokenGrant(
        authorizationId: 'authorization-1',
        subject: 'account-1',
        clientId: 'client-1',
        audiences: ['https://api.example.test'],
        scopes: ['profile.read'],
        expiresAt: 1000,
    );
    $current = new RefreshTokenRecord(
        tokenId: str_repeat('A', 32),
        familyId: $familyId,
        grant: $grant,
        issuedAt: 100,
        idleExpiresAt: 900,
    );
    $replacement = new RefreshTokenRecord(
        tokenId: str_repeat('B', 32),
        familyId: $familyId,
        grant: $grant,
        issuedAt: 200,
        idleExpiresAt: 900,
    );
    $replayReplacement = new RefreshTokenRecord(
        tokenId: str_repeat('C', 32),
        familyId: $familyId,
        grant: $grant,
        issuedAt: 201,
        idleExpiresAt: 900,
    );

    try {
        $runner->run();
        expect($store->create($current))->toBeTrue();

        $first = $store->rotate($current, $replacement, 'client-1', null, 200);
        $second = $store->rotate($current, $replayReplacement, 'client-1', null, 201);

        expect($first)->toBe(RefreshTokenRotationStatus::ROTATED)
            ->and($second)->toBe(RefreshTokenRotationStatus::REUSED)
            ->and($store->inspect($replacement, 202))->toBe(RefreshTokenInspectionStatus::REVOKED)
            ->and($store->inspect($replayReplacement, 202))->toBe(RefreshTokenInspectionStatus::INVALID);
    } finally {
        DB::purge();
    }
});
