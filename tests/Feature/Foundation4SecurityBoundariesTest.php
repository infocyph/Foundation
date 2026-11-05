<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationManager;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetManager;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetStatus;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Auth\Support\InMemoryEmailVerificationStore;
use Infocyph\Foundation\Auth\Support\InMemoryPasswordResetStore;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationStatus;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
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

    // The notifier independently enforces account ownership, even for a
    // directly constructed verification notification bypassing its manager.
    $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'victim',
        ['email' => 'victim@example.test', 'token' => 'verification-token'],
    ));
    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'victim',
        ['email' => 'new-address@example.test', 'token' => 'forged-token'],
    )))->toThrow(RuntimeException::class, 'account-stored destination');
    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'unknown-account',
        ['email' => 'attacker@example.test', 'token' => 'forged-token'],
    )))->toThrow(RuntimeException::class, 'account-stored destination');

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

it('does not treat a display-name mailbox username as the trusted account recipient', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account(
        'username-owner',
        'Eve <attacker@example.test>',
        metadata: ['email' => 'trusted-owner@example.test'],
    ));
    $mailer = Emailer::fake();
    $notifier = new TalkingBytesAuthNotifier(
        $mailer,
        new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
        $accounts,
        from: 'sender@example.test',
    );

    $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORD_RESET_REQUESTED,
        'username-owner',
        ['token' => 'reset-token', 'recipient_email' => 'attacker@example.test'],
    ));
    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'username-owner',
        ['email' => 'attacker@example.test', 'token' => 'forged-token'],
    )))->toThrow(RuntimeException::class, 'account-stored destination');
    $notifier->send(new AuthNotification(
        AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
        'username-owner',
        ['email' => 'trusted-owner@example.test', 'token' => 'trusted-token'],
    ));

    $mailer->assertable()->assertSentCount(2);
    $mailer->assertable()->assertSentTo('trusted-owner@example.test');
    expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))
        ->toThrow(RuntimeException::class);
});

it('binds every security notification purpose to a trusted recipient despite nested overrides', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account('notification-owner', 'recipient@example.test'));
    $accounts->save(new Account('missing-email', 'username-only', metadata: [
        'email' => 'not-a-valid-address',
    ]));
    $mailer = Emailer::fake();
    $notifier = new TalkingBytesAuthNotifier(
        $mailer,
        new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
        $accounts,
        from: 'sender@example.test',
    );

    $issued = 0;
    foreach (AuthNotificationType::cases() as $type) {
        $payload = [
            'email' => 'attacker@example.test',
            'identifier' => 'attacker@example.test',
            'recipient_email' => 'attacker@example.test',
            'account_email' => 'attacker@example.test',
            'token' => 'purpose-' . $type->value,
            'challenge_id' => 'challenge-' . $type->value,
            'context' => [
                'email' => 'attacker@example.test',
                'account_email' => 'attacker@example.test',
                'recipient_email' => 'attacker@example.test',
            ],
        ];
        if ($type === AuthNotificationType::EMAIL_VERIFICATION_REQUESTED) {
            $payload['email'] = 'recipient@example.test';
        }
        if ($type === AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED) {
            $payload['identifier'] = 'recipient@example.test';
        }

        $accountId = $type === AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED
            ? null
            : 'notification-owner';
        $notifier->send(new AuthNotification($type, $accountId, $payload));
        $mailer->assertable()->assertSentCount(++$issued);
        $metadata = $mailer->assertable()->lastMessage()?->metadata();
        expect($metadata['auth_notification_type'] ?? null)->toBe($type->value)
            ->and($metadata['account_id'] ?? null)->toBe($accountId);
    }

    expect($issued)->toBeCount(13);
    $mailer->assertable()->assertSentTo('recipient@example.test');
    expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))
        ->toThrow(RuntimeException::class);

    expect(fn() => $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORD_RESET_REQUESTED,
        'missing-email',
        ['token' => 'blocked'],
    )))->toThrow(RuntimeException::class, 'valid trusted email recipient');
    $notifier->send(new AuthNotification(
        AuthNotificationType::LOGIN_ALERT,
        'missing-email',
        ['email' => 'attacker@example.test'],
    ));
    $notifier->send(new AuthNotification(
        AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED,
        null,
        ['identifier' => 'unknown@example.test', 'email' => 'attacker@example.test'],
    ));
    $mailer->assertable()->assertSentCount($issued);
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

it('preserves encoded JSON zero in authentication notification templates and payload lines', function (): void {
    $registry = new NotificationTemplateRegistry(new ConfigRepository([
        'notifications' => [
            'auth' => [
                'templates' => [
                    'login_alert' => ['text' => '{{counter}}|{{payload_lines}}'],
                ],
            ],
        ],
    ]));
    $zero = new class implements JsonSerializable {
        public function jsonSerialize(): int
        {
            return 0;
        }
    };
    $message = new AuthNotificationMapper($registry)->toEmail(new AuthNotification(
        AuthNotificationType::LOGIN_ALERT,
        'account-id',
        ['counter' => $zero],
    ), 'owner@example.test');

    expect($message->textBody())->toBe('0|counter: 0');
});

it('rejects verified email tokens whose subject or destination differs from the stored request', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account('victim', 'victim@example.test', AccountStatus::PENDING_VERIFICATION));
    $store = new InMemoryEmailVerificationStore();
    $now = time();
    $store->save(new EmailVerificationRequest('email-request', 'victim', 'victim@example.test', $now, $now + 3600));

    foreach ([
        new TokenVerificationResult(true, 'attacker', claims: [
            'request_id' => 'email-request',
            'email' => 'victim@example.test',
        ]),
        new TokenVerificationResult(true, 'victim', claims: [
            'request_id' => 'email-request',
            'email' => 'attacker@example.test',
        ]),
    ] as $verification) {
        $tokens = new class($verification) implements EmailVerificationTokenServiceInterface {
            public function __construct(private TokenVerificationResult $verification) {}

            public function issue(string $accountId, string $email, array $context = []): string
            {
                return $accountId . ':' . $email . ':' . count($context);
            }

            public function verify(string $token): TokenVerificationResult
            {
                return $token === 'test-token'
                    ? $this->verification
                    : new TokenVerificationResult(false, failureReason: 'invalid_token');
            }
        };

        $manager = new EmailVerificationManager(
            $tokens,
            $store,
            $accounts,
            $accounts,
            new CollectingAuthNotifier(),
            new InMemoryAuditEventStore(),
            new UidAuthIdGenerator(new ConfigRepository()),
        );

        $result = $manager->verify('test-token');
        expect($result->status)->toBe(EmailVerificationStatus::INVALID)
            ->and($result->code)->toBe('verification_request_mismatch')
            ->and($store->find('email-request')?->isConsumed())->toBeFalse()
            ->and($accounts->findById('victim')?->status())->toBe(AccountStatus::PENDING_VERIFICATION);
    }
});

it('rejects a verified password reset token for another stored account', function (): void {
    $accounts = new InMemoryAccountStore();
    $accounts->save(new Account('victim', 'victim@example.test', passwordHash: 'original-hash'));
    $store = new InMemoryPasswordResetStore();
    $now = time();
    $store->save(new PasswordResetRequest('reset-request', 'victim', $now, $now + 3600));

    $tokens = new class implements PasswordResetTokenServiceInterface {
        public function issue(string $accountId, array $context = []): string
        {
            return $accountId . ':' . count($context);
        }

        public function verify(string $token): TokenVerificationResult
        {
            return new TokenVerificationResult(
                $token === 'test-token',
                'attacker',
                claims: ['request_id' => 'reset-request'],
            );
        }
    };

    $manager = new PasswordResetManager(
        $tokens,
        $store,
        $accounts,
        new CollectingAuthNotifier(),
        new InMemoryAuditEventStore(),
        new UidAuthIdGenerator(new ConfigRepository()),
    );

    $result = $manager->complete('test-token', 'attacker-hash');
    expect($result->status)->toBe(PasswordResetStatus::INVALID)
        ->and($result->code)->toBe('reset_request_mismatch')
        ->and($store->wasConsumed('reset-request'))->toBeFalse()
        ->and($accounts->findById('victim')?->passwordHash())->toBe('original-hash');
});

it('keeps bearer issuance tokens in trusted auth services but never in HTTP-facing action results', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $actions = $app->make(AuthActions::class);
        $account = $services->accounts()->create(
            'bearer-boundary@example.test',
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        expect($account)->not->toBeNull();
        if ($account === null) {
            throw new LogicException('Expected a persisted account.');
        }

        $email = $actions->requestEmailVerification(['account_id' => $account->id()]);
        $reset = $actions->requestPasswordReset(['identifier' => $account->identifier()]);
        $passwordless = $actions->requestPasswordless(['identifier' => $account->identifier()]);

        expect($email->successful())->toBeTrue()
            ->and($email->token)->toBeNull()
            ->and($email->email())->toBe($account->identifier())
            ->and($reset->successful())->toBeTrue()
            ->and($reset->token)->toBeNull()
            ->and($passwordless->successful())->toBeTrue()
            ->and($passwordless->token)->toBeNull();

        foreach ([$email, $reset, $passwordless] as $publicResult) {
            $encoded = json_encode($publicResult, JSON_THROW_ON_ERROR);
            expect($encoded)->toContain('"token":null');
        }

        expect($services->emailVerification()->issue($account->id(), $account->identifier())->token)
            ->toBeString()
            ->and($services->passwordResets()->issue($account->id())->token)
            ->toBeString()
            ->and($services->passwordless()->issue($account->identifier())->token)
            ->toBeString();
    });
});

it('binds HTTP password reset delivery and token subject for account ID and identifier lookup', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create('reset-owner@example.test')->account;
        if ($account === null) {
            throw new LogicException('Expected a persisted account.');
        }

        $notifications = $app->make(AuthNotifierInterface::class);
        if (!$notifications instanceof CollectingAuthNotifier) {
            throw new LogicException('Expected collecting notifier.');
        }
        $mailer = Emailer::fake();
        $outbound = new TalkingBytesAuthNotifier(
            $mailer,
            new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
            $services->accountProvider(),
            from: 'sender@example.test',
        );
        $actions = $app->make(AuthActions::class);

        foreach ([
            ['account_id' => $account->id()],
            ['identifier' => $account->identifier()],
        ] as $lookup) {
            $result = $actions->requestPasswordReset($lookup + [
                'email' => 'attacker@example.test',
                'recipient_email' => 'attacker@example.test',
                'context' => [
                    'email' => 'attacker@example.test',
                    'account_email' => 'attacker@example.test',
                    'recipient_email' => 'attacker@example.test',
                ],
            ]);
            $queued = $notifications->notifications();
            $notification = $queued[count($queued) - 1] ?? null;
            if (!$notification instanceof AuthNotification) {
                throw new LogicException('Expected the password reset notification.');
            }

            expect($result->token)->toBeNull()
                ->and($notification->type)->toBe(AuthNotificationType::PASSWORD_RESET_REQUESTED)
                ->and($notification->accountId)->toBe($account->id());

            $token = $notification->payload['token'] ?? null;
            expect($token)->toBeString();
            if (!is_string($token)) {
                throw new LogicException('Expected a reset token.');
            }

            $outbound->send($notification);
            expect($services->passwordResets()->complete($token, 'replacement-hash')->completed())->toBeTrue()
                ->and($services->accountProvider()->findById($account->id())?->passwordHash())->toBe('replacement-hash');
        }

        $mailer->assertable()->assertSentCount(2);
        $mailer->assertable()->assertSentTo('reset-owner@example.test');
        expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))
            ->toThrow(RuntimeException::class);
    });
});

it('keeps verified email and passwordless tokens bound to trusted notification recipients', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create(
            'purpose-owner@example.test',
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        if ($account === null) {
            throw new LogicException('Expected a persisted account.');
        }
        $notifications = $app->make(AuthNotifierInterface::class);
        if (!$notifications instanceof CollectingAuthNotifier) {
            throw new LogicException('Expected collecting notifier.');
        }
        $mailer = Emailer::fake();
        $outbound = new TalkingBytesAuthNotifier(
            $mailer,
            new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
            $services->accountProvider(),
            from: 'sender@example.test',
        );

        $actions = $app->make(AuthActions::class);
        $email = $actions->requestEmailVerification([
            'account_id' => $account->id(),
            'email' => $account->identifier(),
            'context' => ['email' => 'attacker@example.test', 'recipient_email' => 'attacker@example.test'],
        ]);
        $passwordless = $actions->requestPasswordless([
            'identifier' => $account->identifier(),
            'email' => 'attacker@example.test',
            'context' => ['recipient_email' => 'attacker@example.test'],
        ]);
        expect($email->token)->toBeNull()
            ->and($passwordless->token)->toBeNull();

        $issued = $notifications->notifications();
        expect($issued)->toHaveCount(2);
        foreach ($issued as $notification) {
            $outbound->send($notification);
            $token = $notification->payload['token'] ?? null;
            expect($token)->toBeString();
            if (!is_string($token)) {
                throw new LogicException('Expected a delivered token.');
            }

            if ($notification->type === AuthNotificationType::EMAIL_VERIFICATION_REQUESTED) {
                expect($notification->accountId)->toBe($account->id())
                    ->and($services->emailVerification()->verify($token)->verified())->toBeTrue();
            } else {
                expect($notification->type)->toBe(AuthNotificationType::PASSWORDLESS_LOGIN_REQUESTED)
                    ->and($services->passwordless()->verify($token)->successful())->toBeTrue();
            }
        }

        $mailer->assertable()->assertSentCount(2);
        $mailer->assertable()->assertSentTo('purpose-owner@example.test');
        expect(fn() => $mailer->assertable()->assertSentTo('attacker@example.test'))
            ->toThrow(RuntimeException::class);
    });
});

it('rejects untrusted direct domain email verification destinations before token issuance', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create(
            'domain-owner@example.test',
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        if ($account === null) {
            throw new LogicException('Expected a persisted account.');
        }
        $notifier = $app->make(AuthNotifierInterface::class);
        if (!$notifier instanceof CollectingAuthNotifier) {
            throw new LogicException('Expected collecting notifier.');
        }

        $manager = $services->emailVerification();
        $forged = $manager->issue($account->id(), 'attacker@example.test', [
            'recipient_email' => 'attacker@example.test',
        ]);
        $missing = $manager->issue('missing-account', 'attacker@example.test');

        expect($forged->status)->toBe(EmailVerificationStatus::INVALID)
            ->and($forged->code)->toBe('email_destination_untrusted')
            ->and($forged->token)->toBeNull()
            ->and($missing->status)->toBe(EmailVerificationStatus::INVALID)
            ->and($notifier->notifications())->toBeEmpty();

        $issued = $manager->issue($account->id(), $account->identifier(), [
            'email' => 'attacker@example.test',
            'recipient_email' => 'attacker@example.test',
        ]);
        expect($issued->status)->toBe(EmailVerificationStatus::ISSUED)
            ->and($issued->token)->toBeString()
            ->and($notifier->notifications())->toHaveCount(1)
            ->and($notifier->notifications()[0]->payload['email'])->toBe($account->identifier());

        $username = $services->accounts()->create('domain-user', metadata: [
            'email' => 'bound-owner@example.test',
        ])->account;
        if ($username === null) {
            throw new LogicException('Expected a username-backed account.');
        }
        expect($manager->issue($username->id(), 'attacker@example.test')->failed())->toBeTrue()
            ->and($manager->issue($username->id(), 'bound-owner@example.test')->successful())->toBeTrue();
    });
});

it('blocks queued verification mail after an address change and permits a newly bound request', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create(
            'queued-email-user',
            metadata: ['email' => 'old-delivery@example.test'],
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        if ($account === null) {
            throw new LogicException('Expected a username-backed account.');
        }
        $notifications = $app->make(AuthNotifierInterface::class);
        if (!$notifications instanceof CollectingAuthNotifier) {
            throw new LogicException('Expected a collecting notifier.');
        }
        $mailer = Emailer::fake();
        $outbound = new TalkingBytesAuthNotifier(
            $mailer,
            new AuthNotificationMapper(new NotificationTemplateRegistry(new ConfigRepository())),
            $services->accountProvider(),
            from: 'sender@example.test',
        );

        $first = $services->emailVerification()->issue($account->id(), 'old-delivery@example.test');
        $queued = $notifications->notifications();
        expect($first->status)->toBe(EmailVerificationStatus::ISSUED)
            ->and($queued)->toHaveCount(1)
            ->and($queued[0]->accountId)->toBe($account->id())
            ->and($queued[0]->payload['email'])->toBe('old-delivery@example.test');

        expect($services->accounts()->updateMetadata(
            $account->id(),
            ['email' => 'new-delivery@example.test'],
        )->successful())->toBeTrue();
        expect(fn() => $outbound->send($queued[0]))->toThrow(
            RuntimeException::class,
            'account-stored destination',
        );
        $mailer->assertable()->assertSentCount(0);

        if ($first->token === null) {
            throw new LogicException('Expected an issued verification token.');
        }
        expect($services->emailVerification()->verify($first->token)->status)->toBe(EmailVerificationStatus::INVALID);

        $second = $services->emailVerification()->issue($account->id(), 'new-delivery@example.test');
        $newQueue = $notifications->notifications();
        expect($second->status)->toBe(EmailVerificationStatus::ISSUED)
            ->and($newQueue)->toHaveCount(2)
            ->and($newQueue[1]->accountId)->toBe($account->id());
        $outbound->send($newQueue[1]);

        $mailer->assertable()->assertSentCount(1);
        $mailer->assertable()->assertSentTo('new-delivery@example.test');
        expect(fn() => $mailer->assertable()->assertSentTo('old-delivery@example.test'))
            ->toThrow(RuntimeException::class);
        $metadata = $mailer->assertable()->lastMessage()?->metadata();
        expect($metadata['account_id'] ?? null)->toBe($account->id())
            ->and($metadata['auth_notification_type'] ?? null)->toBe('email_verification_requested');

        if ($second->token === null) {
            throw new LogicException('Expected a replacement verification token.');
        }
        expect($services->emailVerification()->verify($second->token)->verified())->toBeTrue();
    });
});

it('cannot verify a stale email token after the authoritative account email changes', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $app->container()->withinScope('webrick.request', static function () use ($app): void {
        $services = $app->make(AuthServices::class);
        $account = $services->accounts()->create(
            'previous-email-user',
            metadata: ['email' => 'previous@example.test'],
            status: AccountStatus::PENDING_VERIFICATION,
        )->account;
        if ($account === null) {
            throw new LogicException('Expected an account.');
        }

        $issued = $services->emailVerification()->issue($account->id(), 'previous@example.test');
        expect($issued->status)->toBe(EmailVerificationStatus::ISSUED)
            ->and($issued->token)->toBeString();
        if ($issued->request === null || $issued->token === null) {
            throw new LogicException('Expected a persisted verification request and token.');
        }

        expect($services->accounts()->updateMetadata(
            $account->id(),
            ['email' => 'current@example.test'],
        )->successful())->toBeTrue();

        $verified = $services->emailVerification()->verify($issued->token);
        $store = $app->make(\Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface::class);
        expect($verified->status)->toBe(EmailVerificationStatus::INVALID)
            ->and($verified->code)->toBe('email_destination_untrusted')
            ->and($store->find($issued->request->id)?->isConsumed())->toBeFalse()
            ->and($services->accountProvider()->findById($account->id())?->status())->toBe(AccountStatus::PENDING_VERIFICATION);
    });
});
