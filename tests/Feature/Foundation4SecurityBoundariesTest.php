<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationStatus;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\InMemoryAccountStore;
use Infocyph\Foundation\Auth\Support\InMemoryAuditEventStore;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Notifications\NotificationTemplateRegistry;
use Infocyph\TalkingBytes\Email\Emailer;

it('delivers account password resets only to the authoritative stored address', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account('victim', 'victim@example.test'));
    $mailer = Emailer::fake();
    $notifier = new TalkingBytesAuthNotifier(
        $mailer,
        new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
        $accounts,
        from: 'sender@example.test',
    );

    $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORD_RESET_REQUESTED,
        'victim',
        [
            'token' => 'test-reset-token',
            'request_id' => 'req-1',
            'email' => 'attacker@example.test',
            'recipient_email' => 'attacker@example.test',
            'context' => ['account_email' => 'attacker@example.test'],
        ],
    ));

    $mailer->assertable()->assertSentCount(1);
    $mailer->assertable()->assertSentTo('victim@example.test');
    $metadata = $mailer->assertable()->lastMessage()?->metadata();
    expect($metadata['account_id'] ?? null)->toBe('victim')
        ->and($metadata['auth_notification_type'] ?? null)->toBe('password_reset_requested')
        ->and($metadata)->not->toHaveKey('token')
        ->and($metadata)->not->toHaveKey('context');

    expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))->toThrow(RuntimeException::class);

    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORD_RESET_REQUESTED,
        'unknown-account',
        ['email' => 'attacker@example.test', 'token' => 'test'],
    )))->toThrow(RuntimeException::class, 'existing account recipient');

    // Email verification carries an explicit destination chosen by its manager.
    $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'victim',
        ['email' => 'new-address@example.test', 'token' => 'verification-token'],
    ));
    $mailer->assertable()->assertSentTo('new-address@example.test');

    // Passwordless issuance is keyed to the requested identifier; ancillary
    // caller-supplied email fields may not redirect its bearer token.
    $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED,
        null,
        ['identifier' => 'victim@example.test', 'email' => 'attacker@example.test', 'token' => 'test-passwordless-token'],
    ));
    $notifier->send(new AuthNotification(
        AuthNotificationType::MFA_CHALLENGE_REQUESTED,
        'victim',
        ['recipient_email' => 'attacker@example.test', 'challenge_id' => 'challenge-1'],
    ));
    $notifier->send(new AuthNotification(
        AuthNotificationType::LOGIN_ALERT,
        'victim',
        ['email' => 'attacker@example.test', 'request_id' => 'req-2'],
    ));
    $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED,
        null,
        ['identifier' => 'unknown@example.test', 'email' => 'attacker@example.test', 'token' => 'no-delivery'],
    ));

    $mailer->assertable()->assertSentCount(5);
    $mailer->assertable()->assertSentTo('victim@example.test');
    expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))
        ->toThrow(RuntimeException::class);
    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'victim',
        ['recipient_email' => 'attacker@example.test', 'token' => 'missing-address'],
    )))->toThrow(RuntimeException::class, 'explicit destination');
});

it('redacts nested credentials at the shared authentication audit recorder boundary', function (): void {
    $audit = new InMemoryAuditEventStore();
    AuthEventRecorder::record(
        $audit,
        new UidAuthIdGenerator(new ConfigRepository()),
        new SystemClock(),
        AuthEventType::LOGIN_SUCCESS,
        'victim',
        metadata: [
            'request_id' => 'safe-request',
            'outcome' => 'success',
            'context' => [
                'password' => 'password-sentinel',
                'nested' => ['AccessToken' => 'access-sentinel', 'client-secret' => 'secret-sentinel'],
                'safe' => ['device' => 'browser'],
            ],
            'Authorization' => 'Bearer bearer-sentinel',
        ],
    );

    $metadata = $audit->events()[0]->metadata;
    expect($metadata['request_id'])->toBe('safe-request')
        ->and($metadata['context']['safe'])->toBe(['device' => 'browser'])
        ->and($metadata['context']['password'])->toBe('[REDACTED]');
    $encoded = json_encode($metadata, JSON_THROW_ON_ERROR);
    foreach (['password-sentinel', 'access-sentinel', 'secret-sentinel', 'bearer-sentinel'] as $secret) {
        expect($encoded)->not->toContain($secret);
    }
});

it('allows HTTP-facing verification issuance only to an account-stored email', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $actions = $app->make(AuthActions::class);
        $account = $services->accounts()->create(
            'victim@example.test',
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        expect($account)->not->toBeNull();
        if ($account === null) {
            throw new LogicException('Expected a stored account.');
        }

        $forged = $actions->requestEmailVerification([
            'account_id' => $account->id(),
            'email' => 'attacker@example.test',
            'context' => ['email' => 'attacker@example.test'],
        ]);
        expect($forged->status)->toBe(EmailVerificationStatus::INVALID)
            ->and($forged->code)->toBe('email_destination_untrusted')
            ->and($forged->token)->toBeNull();

        $legitimate = $actions->requestEmailVerification([
            'account_id' => $account->id(),
            'email' => 'victim@example.test',
        ]);
        expect($legitimate->status)->toBe(EmailVerificationStatus::ISSUED)
            ->and($legitimate->email())->toBe('victim@example.test');

        $username = $services->accounts()->create(
            'victim-user',
            metadata: ['email' => 'account@example.test'],
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        expect($username)->not->toBeNull();
        if ($username === null) {
            throw new LogicException('Expected a stored username account.');
        }

        expect($actions->requestEmailVerification([
            'account_id' => $username->id(),
        ])->email())->toBe('account@example.test');
        expect($actions->requestEmailVerification([
            'account_id' => $username->id(),
            'email' => 'attacker@example.test',
        ])->status)->toBe(EmailVerificationStatus::INVALID);
    });
});

it('keeps caller metadata out of authoritative audit session and device identity', function (): void {
    $audit = new InMemoryAuditEventStore();
    $ids = new UidAuthIdGenerator(new ConfigRepository());
    $clock = new SystemClock();

    AuthEventRecorder::record(
        $audit,
        $ids,
        $clock,
        AuthEventType::LOGIN_SUCCESS,
        'account-id',
        metadata: ['session_id' => 'supplied-session', 'device_id' => 'supplied-device'],
    );
    AuthEventRecorder::record(
        $audit,
        $ids,
        $clock,
        AuthEventType::LOGIN_SUCCESS,
        'account-id',
        metadata: ['session_id' => 'supplied-session', 'device_id' => 'supplied-device'],
        sessionId: 'server-session',
        deviceId: 'server-device',
    );

    $events = $audit->events();
    expect($events[0]->sessionId)->toBeNull()
        ->and($events[0]->deviceId)->toBeNull()
        ->and($events[1]->sessionId)->toBe('server-session')
        ->and($events[1]->deviceId)->toBe('server-device');
});

it('refuses caller context as authoritative audit identity through credential and token managers', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create('provenance@example.test')->account;
        expect($account)->not->toBeNull();
        if ($account === null) {
            throw new LogicException('Expected an account.');
        }

        $context = ['session_id' => 'forged-session', 'device_id' => 'forged-device'];
        $services->passwordResets()->issue($account->id(), $context);
        $services->emailVerification()->issue($account->id(), $account->identifier(), $context);
        $now = time();
        $services->tokens()->issueAccessToken(new AccessTokenClaims(
            subjectId: $account->id(),
            actorId: null,
            issuedAt: $now,
            expiresAt: $now + 60,
        ), $context);
        $services->tokens()->issueRefreshToken($account->id(), metadata: $context);
        $services->tokens()->revokeRefreshFamily('unbound-family', ['account_id' => 'forged-account'] + $context);
        $services->rememberMe()->issue($account->id(), 'stored-device', $context);

        $audit = $app->make(AuditEventStoreInterface::class);
        expect($audit)->toBeInstanceOf(InMemoryAuditEventStore::class);
        if (!$audit instanceof InMemoryAuditEventStore) {
            throw new LogicException('Expected the in-memory audit store.');
        }

        $events = $audit->events();
        expect($events)->toHaveCount(6);
        foreach ($events as $event) {
            expect($event->sessionId)->toBeNull();
            if ($event->type !== AuthEventType::REMEMBER_TOKEN_ISSUED) {
                expect($event->deviceId)->toBeNull();
            }
        }
        expect($events[5]->deviceId)->toBe('stored-device')
            ->and($events[4]->type)->toBe(AuthEventType::REFRESH_TOKEN_REVOKED)
            ->and($events[4]->accountId)->toBeNull()
            ->and($events[4]->actorId)->toBeNull();
    });
});

it('does not promote caller supplied password-change audit IDs and preserves password verification', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $hasher = $services->passwordHasher();
        $account = $services->accounts()->create(
            'change-provenance@example.test',
            $hasher->hash('initial-password'),
        )->account;
        expect($account)->not->toBeNull();
        if ($account === null) {
            throw new LogicException('Expected an account.');
        }

        $forged = ['session_id' => 'untrusted-session', 'device_id' => 'untrusted-device'];
        expect($services->passwordChanges()->change(
            $account->id(),
            'initial-password',
            $hasher->hash('next-password'),
            $forged,
        )->successful())->toBeTrue();

        $audit = $app->make(AuditEventStoreInterface::class);
        expect($audit)->toBeInstanceOf(InMemoryAuditEventStore::class);
        if (!$audit instanceof InMemoryAuditEventStore) {
            throw new LogicException('Expected an in-memory audit store.');
        }
        $event = $audit->events()[0] ?? null;
        expect($event?->type)->toBe(AuthEventType::PASSWORD_CHANGED)
            ->and($event?->accountId)->toBe($account->id())
            ->and($event?->sessionId)->toBeNull()
            ->and($event?->deviceId)->toBeNull();
    });
});
