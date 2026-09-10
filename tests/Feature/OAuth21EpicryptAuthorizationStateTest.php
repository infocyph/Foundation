<?php

declare(strict_types=1);

use Infocyph\DBLayer\DB;
use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeConsumeStatus;
use Infocyph\Epicrypt\Auth\OAuth\AuthorizationCodeRecord;
use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRecord;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptAuthorizationCodeStore;
use Infocyph\Foundation\Auth\Adapter\DBLayer\OAuth\DBLayerEpicryptOAuthAuthorizationStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthOAuthRevisionSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('persists only authorization-code state and consumes it atomically', function (): void {
    DB::purge();
    $factory = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'oauth-code-state',
            'connections' => [
                'oauth-code-state' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ])), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)])->run();
    $store = new DBLayerEpicryptAuthorizationCodeStore($factory, $tables);
    $now = 1_700_000_000;
    $record = new AuthorizationCodeRecord(
        codeId: str_repeat('A', 32),
        authorizationId: 'authorization-1',
        expiresAt: $now + 60,
        stateDigest: hash('sha256', 'authorization-code-state'),
    );

    try {
        expect($store->create($record))->toBeTrue()
            ->and($store->create($record))->toBeFalse();

        $wrong = new AuthorizationCodeRecord(
            codeId: $record->codeId,
            authorizationId: $record->authorizationId,
            expiresAt: $record->expiresAt,
            stateDigest: hash('sha256', 'different-state'),
        );
        expect($store->consume($wrong, $now))->toBe(AuthorizationCodeConsumeStatus::INVALID)
            ->and($store->consume($record, $now))->toBe(AuthorizationCodeConsumeStatus::CONSUMED)
            ->and($store->consume($record, $now))->toBe(AuthorizationCodeConsumeStatus::REPLAYED);

        $rows = $factory->connection()->select(
            'SELECT * FROM ' . $tables->oauthAuthorizationCodeStates() . ' WHERE id = ?',
            [$record->codeId],
        );
        expect($rows)->toHaveCount(1)
            ->and(array_keys($rows[0]))->toBe([
                'id',
                'authorization_id',
                'expires_at',
                'state_digest',
                'consumed_at',
            ])
            ->and($rows[0]['consumed_at'] ?? null)->toBe($now);
    } finally {
        DB::purge();
    }
});

it('distinguishes expired authorization-code state without consuming it', function (): void {
    DB::purge();
    $factory = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'oauth-code-expired',
            'connections' => [
                'oauth-code-expired' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ])), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)])->run();
    $store = new DBLayerEpicryptAuthorizationCodeStore($factory, $tables);
    $record = new AuthorizationCodeRecord(
        codeId: str_repeat('B', 32),
        authorizationId: 'authorization-expired',
        expiresAt: 1_700_000_000,
        stateDigest: hash('sha256', 'expired-state'),
    );

    try {
        expect($store->create($record))->toBeTrue()
            ->and($store->consume($record, 1_700_000_000))->toBe(AuthorizationCodeConsumeStatus::EXPIRED);
    } finally {
        DB::purge();
    }
});

it('projects Epicrypt authorizations through the authoritative Foundation authorization table', function (): void {
    DB::purge();
    $factory = new DBLayerFactory(new DatabaseConnectionResolver(new ConfigRepository([
        'database' => [
            'default' => 'oauth-authorization-state',
            'connections' => [
                'oauth-authorization-state' => ['driver' => 'sqlite', 'database' => ':memory:'],
            ],
        ],
    ])), RuntimeStateContainer::execution());
    $tables = new AuthTables();
    new MigrationRunner($factory->connection(), [new AuthOAuthRevisionSchema($tables)])->run();
    $store = new DBLayerEpicryptOAuthAuthorizationStore($factory, $tables);
    $record = new OAuthAuthorizationRecord(
        authorizationId: 'authorization-1',
        subject: 'account-1',
        clientId: 'oc_client',
        scopes: ['profile.read'],
        audiences: ['https://api.example.test'],
        authorizedAt: 1_700_000_000,
        expiresAt: 1_700_003_600,
    );

    try {
        expect($store->create($record))->toBeTrue()
            ->and($store->create($record))->toBeFalse()
            ->and($store->find($record->authorizationId)?->subject)->toBe('account-1');

        $revoked = $store->revoke($record->authorizationId, 1_700_000_100);
        $again = $store->revoke($record->authorizationId, 1_700_000_200);
        expect($revoked?->revokedAt)->toBe(1_700_000_100)
            ->and($again?->revokedAt)->toBe(1_700_000_100);
    } finally {
        DB::purge();
    }
});
