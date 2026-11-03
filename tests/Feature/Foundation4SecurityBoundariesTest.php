<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\AuthNotificationMapper;
use Infocyph\Foundation\Auth\Adapter\TalkingBytes\TalkingBytesAuthNotifier;
use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Notification\AuthNotification;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\InMemoryAccountStore;
use Infocyph\Foundation\Auth\Support\InMemoryAuditEventStore;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
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
