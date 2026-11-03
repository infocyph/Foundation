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
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('redacts mixed-case nested MFA and bearer credentials before DBLayer persistence', function (): void {
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
        AuthEventRecorder::record(
            $store,
            new UidAuthIdGenerator(new ConfigRepository()),
            new SystemClock(),
            AuthEventType::LOGIN_SUCCESS,
            'account-1',
            metadata: [
                'request_id' => 'safe-request',
                'authorization_id' => 'safe-authorization',
                'TokenId' => 'opaque-identifier',
                'context' => [
                    'AccountPassword' => 'secret-password',
                    'recoveryCodes' => ['secret-recovery-1', 'secret-recovery-2'],
                    'mfaSeed' => 'secret-mfa-seed',
                    'clientAssertion' => 'secret-client-assertion',
                    'apiKey' => 'secret-api-key',
                    'BearerToken' => 'secret-bearer',
                    'nested' => ['privateKey' => 'secret-key', 'device' => 'browser'],
                ],
            ],
        );

        $raw = $connection->table($fixture->tables->auditEvents())->value('metadata');
        expect($raw)->toBeString();
        $metadata = json_decode((string) $raw, true, flags: JSON_THROW_ON_ERROR);
        expect($metadata['request_id'])->toBe('safe-request')
            ->and($metadata['authorization_id'])->toBe('safe-authorization')
            ->and($metadata['TokenId'])->toBe('opaque-identifier')
            ->and($metadata['context']['nested']['device'])->toBe('browser')
            ->and($metadata['context']['recoveryCodes'])->toBe('[REDACTED]');

        foreach ([
            'secret-password', 'secret-recovery-1', 'secret-recovery-2', 'secret-mfa-seed',
            'secret-client-assertion', 'secret-api-key', 'secret-bearer', 'secret-key',
        ] as $secret) {
            expect((string) $raw)->not->toContain($secret);
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
