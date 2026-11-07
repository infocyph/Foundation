<?php

declare(strict_types=1);

use Infocyph\DBLayer\Migration\MigrationRunner;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerAccountStore;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationStatus;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Auth\Support\InMemoryAccountStore;
use Infocyph\Foundation\Auth\Support\InMemoryAuditEventStore;
use Infocyph\Foundation\Auth\Support\InMemoryEmailVerificationStore;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Database\AuthSchema\AuthSchema;
use Infocyph\Foundation\Database\DBLayerFactory;
use Infocyph\Foundation\Tests\Fixtures\AuthContentionFixture;
use Infocyph\Foundation\Tests\Fixtures\RuntimeStateContainer;

it('rejects an address change occurring after verification trust checking but before account mutation', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account('owner', 'account-name', AccountStatus::PENDING_VERIFICATION, metadata: [
        'email' => 'original@example.test',
    ]));
    $requests = new InMemoryEmailVerificationStore();
    $now = time();
    $requests->save(new EmailVerificationRequest('request-1', 'owner', 'original@example.test', $now, $now + 3600));
    $store = new class($requests, $accounts) implements EmailVerificationStoreInterface {
        public function __construct(
            private InMemoryEmailVerificationStore $requests,
            private InMemoryAccountStore $accounts,
        ) {}

        public function consume(string $requestId): void
        {
            $this->requests->consume($requestId);
        }

        public function consumeAndApply(string $requestId, callable $apply): bool
        {
            // Deterministic interleaving, not a timer-based race approximation.
            $this->accounts->updateMetadata('owner', ['email' => 'changed@example.test']);

            return $this->requests->consumeAndApply($requestId, $apply);
        }

        public function find(string $requestId): ?EmailVerificationRequest
        {
            return $this->requests->find($requestId);
        }

        public function save(EmailVerificationRequest $request): void
        {
            $this->requests->save($request);
        }
    };
    $tokens = new class implements EmailVerificationTokenServiceInterface {
        public function issue(string $accountId, string $email, array $context = []): string
        {
            return $accountId . ':' . $email . ':' . count($context);
        }

        public function verify(string $token): TokenVerificationResult
        {
            return new TokenVerificationResult(
                verified: $token === 'test-token',
                subjectId: 'owner',
                claims: ['request_id' => 'request-1', 'email' => 'original@example.test'],
            );
        }
    };
    $notifier = new CollectingAuthNotifier();
    $audit = new InMemoryAuditEventStore();
    $manager = new EmailVerificationManager(
        $tokens,
        $store,
        $accounts,
        $accounts,
        $notifier,
        $audit,
        new UidAuthIdGenerator(new ConfigRepository()),
    );

    $result = $manager->verify('test-token');

    expect($result->status)->toBe(EmailVerificationStatus::INVALID)
        ->and($result->code)->toBe('email_destination_untrusted')
        ->and($requests->find('request-1')?->isConsumed())->toBeFalse()
        ->and($accounts->findById('owner')?->status())->toBe(AccountStatus::PENDING_VERIFICATION)
        ->and($accounts->findById('owner')?->metadata()['verified_at'] ?? null)->toBeNull()
        ->and($audit->events())->toBeEmpty()
        ->and($notifier->notifications())->toBeEmpty();
});

it('conditionally marks a real DBLayer account verified only for its current email', function (): void {
    $database = sys_get_temp_dir() . '/foundation-email-cas-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fixture = new AuthContentionFixture(['driver' => 'sqlite', 'database' => $database]);
    $connection = $fixture->factory->connection();

    try {
        new MigrationRunner(
            $connection,
            [new AuthSchema($fixture->tables)],
            table: 'foundation_email_cas_test_migrations',
        )->run();

        $store = new DBLayerAccountStore(new RuntimeStateContainer([
            DBLayerFactory::class => $fixture->factory,
        ]), $fixture->tables);
        $store->save(new Account('owner', 'username', AccountStatus::PENDING_VERIFICATION, metadata: [
            'email' => 'original@example.test',
            'theme' => 'dark',
        ]));
        $verifiedAt = time();

        expect($store->markVerifiedForEmail('missing', 'original@example.test', $verifiedAt))->toBeFalse()
            ->and($store->markVerifiedForEmail('owner', 'attacker@example.test', $verifiedAt))->toBeFalse()
            ->and($store->findById('owner')?->status())->toBe(AccountStatus::PENDING_VERIFICATION);

        $store->save(new Account('email-owner', 'email-owner@example.test', AccountStatus::PENDING_VERIFICATION));
        $connection->table($fixture->tables->accounts())->where('id', '=', 'email-owner')->update(['metadata' => null]);
        expect($store->markVerifiedForEmail('email-owner', 'EMAIL-OWNER@example.test', $verifiedAt))->toBeTrue()
            ->and($store->findById('email-owner')?->status())->toBe(AccountStatus::ACTIVE)
            ->and($store->findById('email-owner')?->metadata()['verified_at'] ?? null)->toBe($verifiedAt);

        $store->updateMetadata('owner', ['email' => 'current@example.test', 'theme' => 'dark']);

        expect($store->markVerifiedForEmail('owner', 'original@example.test', $verifiedAt))->toBeFalse()
            ->and($store->markVerifiedForEmail('owner', 'CURRENT@example.test', $verifiedAt))->toBeTrue()
            ->and($store->findById('owner')?->status())->toBe(AccountStatus::ACTIVE)
            ->and($store->findById('owner')?->metadata()['verified_at'] ?? null)->toBe($verifiedAt)
            ->and($store->findById('owner')?->metadata()['theme'] ?? null)->toBe('dark');
    } finally {
        $connection->disconnect();
        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});


it('preserves failed DBLayer verification requests and unwraps only successful rollback denials', function (): void {
    $database = sys_get_temp_dir() . '/foundation-email-rollback-' . bin2hex(random_bytes(8)) . '.sqlite';
    $fixture = new AuthContentionFixture(['driver' => 'sqlite', 'database' => $database]);
    $connection = $fixture->factory->connection();

    try {
        new MigrationRunner(
            $connection,
            [new AuthSchema($fixture->tables)],
            table: 'foundation_email_rollback_test_migrations',
        )->run();

        $requests = new \Infocyph\Foundation\Auth\Adapter\DBLayer\DBLayerEmailVerificationStore(
            new RuntimeStateContainer([DBLayerFactory::class => $fixture->factory]),
            $fixture->tables,
            new \Infocyph\Foundation\Auth\Support\SystemClock(),
        );
        $now = time();
        $requests->save(new EmailVerificationRequest('race-request', 'owner', 'owner@example.test', $now, $now + 3600));
        $denial = new \Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailDestinationChanged(
            'Verification destination changed before account mutation.',
        );

        expect(fn() => $requests->consumeAndApply('race-request', static function () use ($denial): void {
            throw $denial;
        }))->toThrow(\Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailDestinationChanged::class)
            ->and($requests->find('race-request')?->isConsumed())->toBeFalse();

        expect(fn() => $requests->consumeAndApply('race-request', static function (): void {
            throw new RuntimeException('unrelated failure');
        }))->toThrow(\Infocyph\DBLayer\Exceptions\TransactionException::class)
            ->and($requests->find('race-request')?->isConsumed())->toBeFalse();
    } finally {
        $connection->disconnect();
        foreach ([$database, $database . '-wal', $database . '-shm'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
});
