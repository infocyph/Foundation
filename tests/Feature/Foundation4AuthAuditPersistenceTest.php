<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerAuditEventStore;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;
use Infocyph\Foundation\Tests\Fixtures\AuthAuditSensitiveKeyMatrix;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('persists no sensitive matrix key across nested login reset MFA and passkey records', function (): void {
    $database = sys_get_temp_dir() . '/foundation-audit-matrix-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fixture = new AuthContentionFixture(['driver' => 'sqlite', 'database' => $database]);
    $connection = $fixture->factory->connection();

    try {
        new MigrationRunner(
            $connection,
            [new AuthSchema($fixture->tables)],
            table: 'foundation_audit_matrix_migrations',
        )->run();

        $store = new DBLayerAuditEventStore(new RuntimeStateContainer([
            DBLayerFactory::class => $fixture->factory,
        ]), $fixture->tables);
        $sensitive = AuthAuditSensitiveKeyMatrix::sensitive();
        $identifiers = AuthAuditSensitiveKeyMatrix::identifiers();
        // Production DBLayer rejects SQL parameters over 1,024 bytes; keep
        // every persisted audit record inside that real security boundary.
        $chunks = array_chunk($sensitive, 12, true);

        foreach ([
            AuthEventType::LOGIN_SUCCESS,
            AuthEventType::PASSWORD_RESET_REQUESTED,
            AuthEventType::MFA_CHALLENGED,
            AuthEventType::PASSKEY_USED,
        ] as $type) {
            foreach ($chunks as $index => $keys) {
                AuthEventRecorder::record(
                    $store,
                    new UidAuthIdGenerator(new ConfigRepository()),
                    new SystemClock(),
                    $type,
                    'account-1',
                    metadata: ['chunk_index' => $index, 'context' => ['nested' => $keys, 'safe' => $identifiers]],
                );
            }
        }

        $rows = $connection->select('SELECT metadata FROM ' . $fixture->tables->auditEvents());
        expect($rows)->toHaveCount(count($chunks) * 4);
        foreach ($rows as $row) {
            $raw = (string) $row['metadata'];
            $metadata = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
            expect($metadata['context']['safe'])->toBe($identifiers);
            $keys = $chunks[$metadata['chunk_index']];
            foreach ($keys as $key => $sentinel) {
                expect($metadata['context']['nested'][$key])->toBe('[REDACTED]');
                expect($raw)->not->toContain($sentinel);
            }
        }
    } finally {
        $connection->disconnect();
        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('does not persist caller session or device metadata as canonical authorization and delegation IDs', function (): void {
    $database = sys_get_temp_dir() . '/foundation-audit-identity-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fixture = new AuthContentionFixture(['driver' => 'sqlite', 'database' => $database]);
    $connection = $fixture->factory->connection();

    try {
        new MigrationRunner(
            $connection,
            [new AuthSchema($fixture->tables)],
            table: 'foundation_audit_identity_migrations',
        )->run();

        $store = new DBLayerAuditEventStore(new RuntimeStateContainer([
            DBLayerFactory::class => $fixture->factory,
        ]), $fixture->tables);
        $ids = new UidAuthIdGenerator(new ConfigRepository());
        $clock = new SystemClock();

        $inner = new class implements \Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface {
            public function authorize(
                \Infocyph\Foundation\Auth\Principal\PrincipalInterface $principal,
                string $ability,
                mixed $resource = null,
                array $context = [],
            ): void {
                unset($principal, $ability, $resource, $context);
                throw new LogicException('The tested path is can().');
            }

            public function can(
                \Infocyph\Foundation\Auth\Principal\PrincipalInterface $principal,
                string $ability,
                mixed $resource = null,
                array $context = [],
            ): \Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision {
                unset($principal, $ability, $resource, $context);
                return \Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision::deny();
            }
        };
        $principal = new \Infocyph\Foundation\Auth\Principal\Principal('account-1', accountId: 'account-1');
        $authorizer = new \Infocyph\Foundation\Auth\Authorization\Gate\AuditingAuthorizer($inner, $store, $ids, $clock);
        $untrusted = ['session_id' => 'forged-session', 'device_id' => 'forged-device'];
        expect($authorizer->can($principal, 'read', context: $untrusted)->allowed)->toBeFalse();

        $grants = new class implements \Infocyph\Foundation\Auth\Authorization\Grant\GrantStoreInterface {
            public function grantsForPrincipal(string $principalId): array
            {
                unset($principalId);
                return [];
            }

            public function revoke(string $grantId): void
            {
                unset($grantId);
            }

            public function save(\Infocyph\Foundation\Auth\Authorization\Grant\AccessGrant $grant): void
            {
                unset($grant);
            }
        };
        $delegation = new \Infocyph\Foundation\Auth\Authorization\Grant\DelegationManager($grants, $store, $ids, $clock);
        $grant = $delegation->grant('account-1', 'read', metadata: $untrusted);
        expect($grant->grant)->not->toBeNull();
        $delegation->revoke($grant->grant?->id ?? '', 'account-1', $untrusted);

        $rows = $connection->select('SELECT type, session_id, device_id, metadata FROM ' . $fixture->tables->auditEvents());
        expect($rows)->toHaveCount(3);
        foreach ($rows as $row) {
            expect($row['session_id'])->toBeNull()
                ->and($row['device_id'])->toBeNull();
            $metadata = json_decode((string) $row['metadata'], true, flags: JSON_THROW_ON_ERROR);
            expect($metadata['session_id'])->toBe('forged-session')
                ->and($metadata['device_id'])->toBe('forged-device');
        }

        AuthEventRecorder::record(
            $store,
            $ids,
            $clock,
            AuthEventType::SESSION_CREATED,
            'account-1',
            metadata: $untrusted,
            sessionId: 'trusted-session',
            deviceId: 'trusted-device',
        );
        $trusted = $connection->select('SELECT session_id, device_id FROM ' . $fixture->tables->auditEvents() . ' WHERE type = ?', [AuthEventType::SESSION_CREATED->value]);
        expect($trusted)->toHaveCount(1)
            ->and($trusted[0]['session_id'])->toBe('trusted-session')
            ->and($trusted[0]['device_id'])->toBe('trusted-device');
    } finally {
        $connection->disconnect();
        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});

it('redacts arbitrary uppercase and mixed-case secrets for all auth-purpose audit rows in DBLayer', function (): void {
    $database = sys_get_temp_dir() . '/foundation-audit-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fixture = new AuthContentionFixture(['driver' => 'sqlite', 'database' => $database]);
    $connection = $fixture->factory->connection();

    try {
        new MigrationRunner(
            $connection,
            [new AuthSchema($fixture->tables)],
            table: 'foundation_audit_test_migrations',
        )->run();

        $store = new DBLayerAuditEventStore(new RuntimeStateContainer([
            DBLayerFactory::class => $fixture->factory,
        ]), $fixture->tables);
        foreach ([
            AuthEventType::LOGIN_SUCCESS,
            AuthEventType::PASSWORD_RESET_REQUESTED,
            AuthEventType::MFA_CHALLENGED,
            AuthEventType::PASSKEY_USED,
        ] as $type) {
            AuthEventRecorder::record(
                $store,
                new UidAuthIdGenerator(new ConfigRepository()),
                new SystemClock(),
                $type,
                'account-1',
                metadata: [
                    'request_id' => 'safe-request',
                    'authorization_id' => 'safe-authorization',
                    'TokenId' => 'opaque-identifier',
                    'TOKEN_ID' => 'opaque-uppercase-identifier',
                    'context' => [
                        'AccountPassword' => 'secret-password',
                        'PASSWORD' => 'uppercase-password-sentinel',
                        'pAsSwOrD' => 'mixed-password-sentinel',
                        'TOKEN' => 'uppercase-token-sentinel',
                        'API_KEY' => 'uppercase-api-key-sentinel',
                        'AUTHORIZATION' => 'uppercase-authorization-sentinel',
                        'PRIVATE_KEY' => 'uppercase-private-key-sentinel',
                        'recoveryCodes' => ['secret-recovery-1', 'secret-recovery-2'],
                        'mfaSeed' => 'secret-mfa-seed',
                        'clientAssertion' => 'secret-client-assertion',
                        'apiKey' => 'secret-api-key',
                        'BearerToken' => 'secret-bearer',
                        'nested' => ['privateKey' => 'secret-key', 'device' => 'browser'],
                    ],
                ],
            );
        }

        $rows = $connection->select('SELECT metadata FROM ' . $fixture->tables->auditEvents());
        expect($rows)->toHaveCount(4);
        foreach ($rows as $row) {
            $raw = $row['metadata'] ?? null;
            expect($raw)->toBeString();
            $metadata = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
            expect($metadata['request_id'])->toBe('safe-request')
                ->and($metadata['authorization_id'])->toBe('safe-authorization')
                ->and($metadata['TokenId'])->toBe('opaque-identifier')
                ->and($metadata['TOKEN_ID'])->toBe('opaque-uppercase-identifier')
                ->and($metadata['context']['nested']['device'])->toBe('browser')
                ->and($metadata['context']['recoveryCodes'])->toBe('[REDACTED]');
            foreach (['PASSWORD', 'pAsSwOrD', 'TOKEN', 'API_KEY', 'AUTHORIZATION', 'PRIVATE_KEY'] as $key) {
                expect($metadata['context'][$key])->toBe('[REDACTED]');
            }

            foreach ([
                'secret-password', 'secret-recovery-1', 'secret-recovery-2', 'secret-mfa-seed',
                'secret-client-assertion', 'secret-api-key', 'secret-bearer', 'secret-key',
                'uppercase-password-sentinel', 'mixed-password-sentinel', 'uppercase-token-sentinel',
                'uppercase-api-key-sentinel', 'uppercase-authorization-sentinel', 'uppercase-private-key-sentinel',
            ] as $secret) {
                expect((string) $raw)->not->toContain($secret);
            }
        }
    } finally {
        $connection->disconnect();
        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
