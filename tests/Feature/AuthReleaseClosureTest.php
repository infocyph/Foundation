<?php

declare(strict_types=1);

use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Notification\AuthNotificationType;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Foundation;

it('emits the email verification notification with the issued token and recipient', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $services = $app->make(AuthServices::class);
    $account = $services->accounts()->create(
        'release@example.test',
        status: AccountStatus::PENDING_VERIFICATION,
    )->account;

    if (!$account instanceof Account) {
        throw new LogicException('Expected an account.');
    }

    $issued = $services->emailVerification()->issue($account->id(), $account->identifier());
    $notifier = $app->make(AuthNotifierInterface::class);
    if (!$notifier instanceof CollectingAuthNotifier) {
        throw new LogicException('Expected the collecting auth notifier.');
    }

    $verificationNotifications = array_values(array_filter(
        $notifier->notifications(),
        static fn($notification): bool => $notification->type === AuthNotificationType::EMAIL_VERIFICATION_REQUESTED,
    ));

    expect($issued->token)->toBeString()->not->toBeEmpty()
        ->and($verificationNotifications)->toHaveCount(1)
        ->and($verificationNotifications[0]->accountId)->toBe($account->id())
        ->and($verificationNotifications[0]->payload['email'] ?? null)->toBe($account->identifier())
        ->and($verificationNotifications[0]->payload['token'] ?? null)->toBe($issued->token)
        ->and($services->emailVerification()->verify($issued->token ?? '')->verified())->toBeTrue();
});

it('distinguishes recent authentication from stale sessions before step-up', function (): void {
    $app = Foundation::web([
        'app' => ['base_path' => sys_get_temp_dir()],
        'router' => ['files' => []],
    ]);
    $stepUp = $app->make(AuthServices::class)->stepUp();
    $now = time();
    $fresh = new AuthSession(
        id: 'recent-session',
        accountId: 'account-1',
        deviceId: null,
        createdAt: $now - 60,
        lastSeenAt: $now,
        expiresAt: $now + 3600,
        recentAuthAt: $now,
    );
    $stale = new AuthSession(
        id: 'stale-session',
        accountId: 'account-1',
        deviceId: null,
        createdAt: $now - 3600,
        lastSeenAt: $now,
        expiresAt: $now + 3600,
        recentAuthAt: $now - 901,
    );

    $freshResult = $stepUp->evaluate($fresh, 'invoice.approve', ['max_age_seconds' => 900]);
    $staleResult = $stepUp->evaluate($stale, 'invoice.approve', ['max_age_seconds' => 900]);

    expect($freshResult->required)->toBeFalse()
        ->and($freshResult->code)->toBe('step_up_not_required')
        ->and($staleResult->required)->toBeTrue()
        ->and($staleResult->code)->toBe('step_up_required');

    $stepUp->markSatisfied($stale->accountId, $stale->id, 'invoice.approve');
    $satisfied = $stepUp->evaluate($stale, 'invoice.approve', ['max_age_seconds' => 900]);
    expect($satisfied->required)->toBeFalse()
        ->and($satisfied->code)->toBe('step_up_already_satisfied');
});
