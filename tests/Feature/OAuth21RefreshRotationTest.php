<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerOAuthRefreshTokenStore;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshRotationStatus;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthRefreshTokenRecord;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;

it('rotates an OAuth refresh token exactly once and classifies replay', function (): void {
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
        new RuntimeContextTracker(),
    );
    $tables = new AuthTables();
    $connection = $factory->connection();
    $revision = new AuthOAuthRevisionSchema($tables);
    $runner = new MigrationRunner($connection, [$revision]);
    $store = new DBLayerOAuthRefreshTokenStore($factory, $tables);

    $current = new OAuthRefreshTokenRecord(
        id: 'refresh-1',
        tokenHash: hash('sha256', 'refresh-token-1'),
        familyId: 'family-1',
        clientId: 'client-1',
        accountId: 'account-1',
        deviceId: 'device-1',
        authorizationId: 'authorization-1',
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
        issuedAt: 100,
        expiresAt: 1000,
    );
    $replacement = new OAuthRefreshTokenRecord(
        id: 'refresh-2',
        tokenHash: hash('sha256', 'refresh-token-2'),
        familyId: 'family-1',
        clientId: 'client-1',
        accountId: 'account-1',
        deviceId: 'device-1',
        authorizationId: 'authorization-1',
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
        issuedAt: 200,
        expiresAt: 1100,
    );
    $replayReplacement = new OAuthRefreshTokenRecord(
        id: 'refresh-3',
        tokenHash: hash('sha256', 'refresh-token-3'),
        familyId: 'family-1',
        clientId: 'client-1',
        accountId: 'account-1',
        deviceId: 'device-1',
        authorizationId: 'authorization-1',
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
        issuedAt: 201,
        expiresAt: 1101,
    );

    try {
        $runner->run();
        $store->save($current);

        $first = $store->rotate($current->tokenHash, $replacement, 200);
        $second = $store->rotate($current->tokenHash, $replayReplacement, 201);

        expect($first->status)->toBe(OAuthRefreshRotationStatus::Rotated)
            ->and($second->status)->toBe(OAuthRefreshRotationStatus::Reused)
            ->and($store->findByHash($replacement->tokenHash)?->id)->toBe('refresh-2')
            ->and($store->findByHash($replayReplacement->tokenHash))->toBeNull();

        $store->revokeFamily('family-1', 202);

        expect($store->findByHash($replacement->tokenHash)?->revokedAt)->toBe(202);
    } finally {
        DB::purge();
    }
});
