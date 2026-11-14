<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Epicrypt\DataProtection\ProtectionAlgorithm;
use Infocyph\Epicrypt\Exception\Crypto\DecryptionException;
use Infocyph\Epicrypt\Generate\KeyMaterial\KeyMaterialGenerator;
use Infocyph\Epicrypt\Security\KeyPurpose;
use Infocyph\Epicrypt\Security\KeyRing;
use Infocyph\Epicrypt\Security\KeyRingEntry;
use Infocyph\Epicrypt\Security\KeyStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerMfaFactorStore;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\MfaSecretProtector;
use Infocyph\Foundation\Auth\Adapter\Otp\OtpRecoveryCodeStore;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DatabaseConnectionResolver;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Psr\Container\ContainerInterface;

function foundationEpicrypt3MfaRing(string $activeId, string $activeKey, ?string $fallbackId = null, ?string $fallbackKey = null): KeyRing
{
    $entries = [
        new KeyRingEntry(
            id: $activeId,
            key: $activeKey,
            status: KeyStatus::ACTIVE,
            purpose: KeyPurpose::DATA_PROTECTION,
            algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        ),
    ];
    if ($fallbackId !== null && $fallbackKey !== null) {
        $entries[] = new KeyRingEntry(
            id: $fallbackId,
            key: $fallbackKey,
            status: KeyStatus::FALLBACK,
            purpose: KeyPurpose::DATA_PROTECTION,
            algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        );
    }

    return new KeyRing($entries);
}

/** @return array{DBLayerFactory,RuntimeExecutionState} */
function foundationEpicrypt3MfaDbFactory(): array
{
    $state = new RuntimeExecutionState();
    $container = new class ($state) implements ContainerInterface {
        public function __construct(private RuntimeExecutionState $state) {}

        /**
         * @throws \RuntimeException
         */
        #[\Override]
        public function get(string $id): mixed
        {
            if ($id === RuntimeExecutionState::class) {
                return $this->state;
            }

            throw new RuntimeException(sprintf('Unknown test service "%s".', $id));
        }

        #[\Override]
        public function has(string $id): bool
        {
            return $id === RuntimeExecutionState::class;
        }
    };
    $config = new ConfigRepository([
        'app' => ['base_path' => sys_get_temp_dir()],
        'database' => [
            'default' => 'default',
            'connections' => [
                'default' => [
                    'driver' => 'sqlite',
                    'database' => ':memory:',
                ],
            ],
        ],
    ]);

    return [new DBLayerFactory(new DatabaseConnectionResolver($config), $container), $state];
}

/**
 * @throws \InvalidArgumentException
 */
function foundationEpicrypt3MfaFactor(string $id = 'factor-1', int $revision = 3): MfaFactor
{
    return new MfaFactor(
        id: $id,
        accountId: 'account-1',
        type: 'totp',
        label: 'Authenticator',
        enabled: true,
        createdAt: 1_700_000_000,
        metadata: [
            'otp' => [
                'algorithm' => 'sha1',
                'digits' => 6,
                'period' => 30,
                'secret' => 'JBSWY3DPEHPK3PXP',
                'pin' => '9284',
            ],
        ],
        revision: $revision,
    );
}

it('protects MFA symmetric secrets with bound AAD and leaves the domain revision unchanged', function (): void {
    $key = (new KeyMaterialGenerator())->forAead();
    $protector = new MfaSecretProtector(foundationEpicrypt3MfaRing('active-1', $key));
    $factor = foundationEpicrypt3MfaFactor();

    $stored = $protector->protect($factor);
    $storedOtp = $stored->metadata['otp'] ?? null;
    if (!is_array($storedOtp) || !is_array($storedOtp['_secret_protection'] ?? null)) {
        throw new \UnexpectedValueException('Protected MFA OTP metadata must contain an envelope.');
    }

    expect($stored->revision)->toBe($factor->revision)
        ->and($storedOtp['secret'])->toStartWith('ep2.')
        ->and($storedOtp['pin'])->toStartWith('ep2.')
        ->and($storedOtp['secret'])->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($storedOtp['pin'])->not->toContain('9284')
        ->and($storedOtp['_secret_protection']['purpose'])->toBe(MfaSecretProtector::PURPOSE)
        ->and($storedOtp['_secret_protection']['version'])->toBe(1);

    $read = $protector->unprotectResult($stored);
    $readOtp = $read->factor->metadata['otp'] ?? null;
    if (!is_array($readOtp)) {
        throw new \UnexpectedValueException('Unprotected MFA OTP metadata must be structured.');
    }
    expect($readOtp['secret'])->toBe('JBSWY3DPEHPK3PXP')
        ->and($readOtp['pin'])->toBe('9284')
        ->and($readOtp)->not->toHaveKey('_secret_protection')
        ->and($read->factor->revision)->toBe($factor->revision)
        ->and($read->usedFallbackKey)->toBeFalse()
        ->and($read->legacyPlaintext)->toBeFalse();

    $wrongFactor = new MfaFactor(
        id: 'different-factor',
        accountId: $stored->accountId,
        type: $stored->type,
        label: $stored->label,
        enabled: $stored->enabled,
        createdAt: $stored->createdAt,
        metadata: $stored->metadata,
        revision: $stored->revision,
    );
    expect(fn() => $protector->unprotect($wrongFactor))->toThrow(DecryptionException::class);
});

it('reads a bounded fallback key and reprotects only on an explicit write boundary', function (): void {
    $generator = new KeyMaterialGenerator();
    $oldKey = $generator->forAead();
    $newKey = $generator->forAead();
    $factor = foundationEpicrypt3MfaFactor();

    $oldProtector = new MfaSecretProtector(foundationEpicrypt3MfaRing('old', $oldKey));
    $oldStored = $oldProtector->protect($factor);

    $rotatedProtector = new MfaSecretProtector(
        foundationEpicrypt3MfaRing('new', $newKey, 'old', $oldKey),
    );
    $fallbackRead = $rotatedProtector->unprotectResult($oldStored);

    \PHPUnit\Framework\Assert::assertIsArray(($fallbackRead->factor->metadata['otp'] ?? null));
    expect($fallbackRead->usedFallbackKey)->toBeTrue()
        ->and($fallbackRead->factor->revision)->toBe($factor->revision)
        ->and(foundationStringMap($fallbackRead->factor->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP');

    $reprotected = $rotatedProtector->protect($fallbackRead->factor);
    $activeRead = $rotatedProtector->unprotectResult($reprotected);

    \PHPUnit\Framework\Assert::assertIsArray(($activeRead->factor->metadata['otp'] ?? null));
    expect($reprotected->revision)->toBe($factor->revision)
        ->and($activeRead->usedFallbackKey)->toBeFalse()
        ->and($activeRead->factor->revision)->toBe($factor->revision)
        ->and(foundationStringMap($activeRead->factor->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP');
});

it('permits legacy plaintext only under the explicit migration policy', function (): void {
    $ring = foundationEpicrypt3MfaRing('active', (new KeyMaterialGenerator())->forAead());
    $factor = foundationEpicrypt3MfaFactor();

    $migrationProtector = new MfaSecretProtector($ring, true);
    $migrationRead = $migrationProtector->unprotectResult($factor);
    expect($migrationRead->legacyPlaintext)->toBeTrue()
        ->and($migrationRead->factor)->toBe($factor);

    $rewritten = $migrationProtector->protect($migrationRead->factor);
    $strict = new MfaSecretProtector($ring);
    expect(foundationStringMap($strict->unprotect($rewritten)->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP')
        ->and(fn() => $strict->unprotect($factor))
        ->toThrow(DecryptionException::class);
});

it('persists only protected MFA secrets while preserving revision based DB CAS', function (): void {
    [$factory, $state] = foundationEpicrypt3MfaDbFactory();
    $tables = new AuthTables();
    (new MigrationRunner($factory->connection(), [new AuthSchema($tables)]))->run();

    $protector = new MfaSecretProtector(
        foundationEpicrypt3MfaRing('active', (new KeyMaterialGenerator())->forAead()),
    );
    $store = new DBLayerMfaFactorStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables, secretProtector: $protector);
    $factor = foundationEpicrypt3MfaFactor(revision: 0);

    expect($store->compareAndSwap(null, $factor))->toBeTrue();

    $row = $factory->connection()->select(
        sprintf('SELECT metadata FROM %s WHERE id = ?', $tables->mfaFactors()),
        [$factor->id],
    )[0] ?? null;
    \PHPUnit\Framework\Assert::assertIsArray($row);

    $raw = is_string($row['metadata'] ?? null) ? $row['metadata'] : '';
    expect($raw)->not->toContain('JBSWY3DPEHPK3PXP')
        ->and($raw)->not->toContain('9284')
        ->and($raw)->toContain('ep2.');

    $loaded = $store->findForAccount('account-1')[0] ?? null;
    if (!$loaded instanceof MfaFactor) {
        throw new RuntimeException('Expected a decrypted MFA factor.');
    }
    \PHPUnit\Framework\Assert::assertIsArray(($loaded->metadata['otp'] ?? null));
    expect(foundationStringMap($loaded->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP')
        ->and($loaded->revision)->toBe(0);

    $updated = $loaded->withMetadata(foundationStringMap(array_replace_recursive(
        $loaded->metadata,
        ['otp' => ['counter' => 7]],
    )));
    expect($store->compareAndSwap($loaded, $updated))->toBeTrue()
        ->and($store->compareAndSwap($loaded, $updated))->toBeFalse()
        ->and(($store->findForAccount('account-1')[0])->revision)->toBe(1)
        ->and(foundationStringMap(($store->findForAccount('account-1')[0])->metadata['otp'])['counter'])->toBe(7);

    $state->cleanup();
});

it('does not let stale fallback-key reprotection overwrite a newer OTP counter revision', function (): void {
    [$factory, $state] = foundationEpicrypt3MfaDbFactory();
    $tables = new AuthTables();
    (new MigrationRunner($factory->connection(), [new AuthSchema($tables)]))->run();

    $generator = new KeyMaterialGenerator();
    $oldKey = $generator->forAead();
    $newKey = $generator->forAead();
    $oldStore = new DBLayerMfaFactorStore(
        new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]),
        $tables,
        secretProtector: new MfaSecretProtector(foundationEpicrypt3MfaRing('old', $oldKey)),
    );
    $rotatedStore = new DBLayerMfaFactorStore(
        new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]),
        $tables,
        secretProtector: new MfaSecretProtector(foundationEpicrypt3MfaRing('new', $newKey, 'old', $oldKey)),
    );
    $factor = foundationEpicrypt3MfaFactor(revision: 0);

    expect($oldStore->compareAndSwap(null, $factor))->toBeTrue();
    $stale = $rotatedStore->findForAccount('account-1')[0] ?? null;
    $current = $rotatedStore->findForAccount('account-1')[0] ?? null;
    if (!$stale instanceof MfaFactor || !$current instanceof MfaFactor) {
        throw new RuntimeException('Rotation test failed to load MFA factors.');
    }
    expect($stale->revision)->toBe($current->revision);

    $counterUpdate = $current->withMetadata(foundationStringMap(array_replace_recursive(
        $current->metadata,
        ['otp' => ['counter' => 11]],
    )));
    expect($rotatedStore->compareAndSwap($current, $counterUpdate))->toBeTrue();

    $staleReprotection = $stale->withMetadata(foundationStringMap(array_replace_recursive(
        $stale->metadata,
        ['otp' => ['counter' => 5]],
    )));
    expect($rotatedStore->compareAndSwap($stale, $staleReprotection))->toBeFalse();

    $latest = $rotatedStore->findForAccount('account-1')[0];
    expect($latest->revision)->toBe(1)
        ->and(foundationStringMap($latest->metadata['otp'])['counter'])->toBe(11)
        ->and(foundationStringMap($latest->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP');

    $state->cleanup();
});

it('fails closed for unknown retired and tampered MFA protection material', function (): void {
    $generator = new KeyMaterialGenerator();
    $oldKey = $generator->forAead();
    $newKey = $generator->forAead();
    $factor = foundationEpicrypt3MfaFactor();

    $stored = new MfaSecretProtector(
        foundationEpicrypt3MfaRing('old', $oldKey),
    )->protect($factor);

    $unknownKeyProtector = new MfaSecretProtector(
        foundationEpicrypt3MfaRing('new', $newKey),
    );
    expect(fn() => $unknownKeyProtector->unprotect($stored))
        ->toThrow(DecryptionException::class);

    $retiredRing = new KeyRing([
        new KeyRingEntry(
            id: 'new',
            key: $newKey,
            status: KeyStatus::ACTIVE,
            purpose: KeyPurpose::DATA_PROTECTION,
            algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        ),
        new KeyRingEntry(
            id: 'old',
            key: $oldKey,
            status: KeyStatus::RETIRED,
            purpose: KeyPurpose::DATA_PROTECTION,
            algorithm: ProtectionAlgorithm::XCHACHA20_POLY1305->value,
        ),
    ]);
    expect(fn() => (new MfaSecretProtector($retiredRing))->unprotect($stored))
        ->toThrow(DecryptionException::class);

    $tamperedMetadata = $stored->metadata;
    \PHPUnit\Framework\Assert::assertIsArray(($tamperedMetadata['otp'] ?? null));
    $ciphertext = $tamperedMetadata['otp']['secret'];
    if (!is_string($ciphertext) || $ciphertext === '') {
        throw new RuntimeException('Expected protected MFA ciphertext.');
    }
    $tamperedMetadata['otp']['secret'] = substr($ciphertext, 0, -1)
        . (str_ends_with($ciphertext, 'A') ? 'B' : 'A');
    $tampered = new MfaFactor(
        id: $stored->id,
        accountId: $stored->accountId,
        type: $stored->type,
        label: $stored->label,
        enabled: $stored->enabled,
        createdAt: $stored->createdAt,
        metadata: $tamperedMetadata,
        revision: $stored->revision,
    );

    expect(fn() => (new MfaSecretProtector(
        foundationEpicrypt3MfaRing('old', $oldKey),
    ))->unprotect($tampered))->toThrow(DecryptionException::class);
});

it('isolates MFA plaintext across sequential and Fiber reuse of the protection service', function (): void {
    $protector = new MfaSecretProtector(
        foundationEpicrypt3MfaRing('active', (new KeyMaterialGenerator())->forAead()),
    );
    $factorA = foundationEpicrypt3MfaFactor('factor-a', 0);
    $factorB = new MfaFactor(
        id: 'factor-b',
        accountId: 'account-2',
        type: 'totp',
        label: 'Authenticator B',
        enabled: true,
        createdAt: 1_700_000_001,
        metadata: [
            'otp' => [
                'algorithm' => 'sha1',
                'digits' => 6,
                'period' => 30,
                'secret' => 'KRUGS4ZANFZSAYJA',
                'pin' => '3141',
            ],
        ],
        revision: 0,
    );
    $storedA = $protector->protect($factorA);
    $storedB = $protector->protect($factorB);

    for ($iteration = 0; $iteration < 32; ++$iteration) {
        expect(foundationStringMap($protector->unprotect($storedA)->metadata['otp'])['secret'])->toBe('JBSWY3DPEHPK3PXP')
            ->and(foundationStringMap($protector->unprotect($storedB)->metadata['otp'])['secret'])->toBe('KRUGS4ZANFZSAYJA');
    }

    $fiberA = new Fiber(function () use ($protector, $storedA): string {
        $first = foundationStringMap($protector->unprotect($storedA)->metadata['otp'])['secret'];
        expect(Fiber::suspend($first))->toBeNull();

        return foundationEpicrypt3MfaSecret($protector->unprotect($storedA));
    });
    $fiberB = new Fiber(function () use ($protector, $storedB): string {
        $first = foundationStringMap($protector->unprotect($storedB)->metadata['otp'])['secret'];
        expect(Fiber::suspend($first))->toBeNull();

        return foundationEpicrypt3MfaSecret($protector->unprotect($storedB));
    });

    expect($fiberA->start())->toBe('JBSWY3DPEHPK3PXP')
        ->and($fiberB->start())->toBe('KRUGS4ZANFZSAYJA');

    $fiberA->resume();
    $fiberB->resume();

    expect($fiberA->getReturn())->toBe('JBSWY3DPEHPK3PXP')
        ->and($fiberB->getReturn())->toBe('KRUGS4ZANFZSAYJA');
});

it('atomically replaces and consumes OTP recovery digests without double consumption', function (): void {
    [$factory, $state] = foundationEpicrypt3MfaDbFactory();
    $tables = new AuthTables();
    (new MigrationRunner($factory->connection(), [new AuthSchema($tables)]))->run();

    $store = new DBLayerMfaFactorStore(new \Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer([\Infocyph\Foundation\Database\DBLayerFactory::class => $factory]), $tables);
    $recovery = new OtpRecoveryCodeStore($store);
    $issuedAt = new DateTimeImmutable('@1700000000');
    $usedAt = new DateTimeImmutable('@1700000100');
    $first = hash('sha256', 'recovery-one');
    $second = hash('sha256', 'recovery-two');
    $replacement = hash('sha256', 'replacement-one');

    expect($recovery->replace('account:account-1', [$first, $second], $issuedAt))->toMatchArray([
        'total' => 2,
        'remaining' => 2,
        'lastUsedAt' => null,
    ]);

    $consumed = $recovery->consume('account:account-1', $first, $usedAt);
    expect($consumed['consumed'])->toBeTrue()
        ->and($consumed['total'])->toBe(2)
        ->and($consumed['remaining'])->toBe(1)
        ->and($recovery->consume('account:account-1', $first, $usedAt)['consumed'])->toBeFalse()
        ->and($recovery->metadata('account:account-1')['remaining'])->toBe(1);

    expect($recovery->replace('account:account-1', [$replacement], $usedAt))->toMatchArray([
        'total' => 1,
        'remaining' => 1,
        'lastUsedAt' => null,
    ])->and($recovery->consume('account:account-1', $second, $usedAt)['consumed'])->toBeFalse()
        ->and($recovery->consume('account:account-1', $replacement, $usedAt)['consumed'])->toBeTrue()
        ->and($recovery->metadata('account:account-1')['remaining'])->toBe(0);

    $state->cleanup();
});

/** @throws UnexpectedValueException */
function foundationEpicrypt3MfaSecret(MfaFactor $factor): string
{
    $metadata = foundationStringMap($factor->metadata['otp'] ?? null);
    $secret = $metadata['secret'] ?? null;
    if (!is_string($secret)) {
        throw new UnexpectedValueException('MFA fixture secret must be a string.');
    }

    return $secret;
}
