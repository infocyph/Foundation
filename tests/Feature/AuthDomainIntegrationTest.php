<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Account\Account;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Authentication\Session\AuthSession;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenRecord;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Authorization\Decision\AuthorizationDecision;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Device\DeviceRecord;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Mfa\MfaChallenge;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Support\CollectingAuthNotifier;
use Infocyph\Foundation\Auth\Support\InMemoryAuditEventStore;
use Infocyph\Foundation\Foundation;

function foundationAuthApplication(): Application
{
    return Foundation::web([
        'app' => [
            'base_path' => sys_get_temp_dir(),
        ],
        'router' => [
            'files' => [],
        ],
    ]);
}

it('composes the complete credential and token lifecycle without eager sibling services', function (): void {
    $app = foundationAuthApplication();
    $services = $app->make(AuthServices::class);
    $actions = $app->make(AuthActions::class);
    $hasher = $services->passwordHasher();
    $created = $services->accounts()->create(
        'person@example.test',
        $hasher->hash('initial-secret'),
        ['tenant' => 'alpha'],
        AccountStatus::PENDING_VERIFICATION,
    );
    $account = $created->account;

    expect($account)->toBeInstanceOf(Account::class);
    if (!$account instanceof Account) {
        throw new LogicException('Expected an account.');
    }

    expect($actions->login([
        'identifier' => 'person@example.test',
        'password' => 'wrong-secret',
    ])->failed())->toBeTrue();

    $verification = $services->emailVerification()->issue(
        $account->id(),
        $account->identifier(),
    );
    expect($verification->token)->not->toBeNull()
        ->and($services->emailVerification()->verify($verification->token ?? '')->verified())->toBeTrue();

    $login = $actions->login([
        'identifier' => 'person@example.test',
        'password' => 'initial-secret',
        'context' => ['device_id' => 'device-1'],
    ]);
    $session = $login->session;

    expect($login->authenticated())->toBeTrue()
        ->and($session)->not->toBeNull();
    if (!$session instanceof AuthSession) {
        throw new LogicException('Expected an authentication session.');
    }

    $sessionStore = $app->make(SessionStoreInterface::class);
    $rotated = $services->sessions()->rotate($session->id);

    expect($sessionStore->find($session->id))->toBeNull()
        ->and($sessionStore->find($rotated->id))->not->toBeNull()
        ->and($services->passwordChanges()->changeWithPlainPassword(
            $account->id(),
            'initial-secret',
            'changed-secret',
            $hasher,
            $services->passwordPolicy(),
        )->successful())->toBeTrue()
        ->and($actions->login([
            'identifier' => 'person@example.test',
            'password' => 'changed-secret',
        ])->authenticated())->toBeTrue();

    $services->principals()->set($login->principal);
    expect($actions->logout($rotated->id)->loggedOut)->toBeTrue()
        ->and($services->principals()->get())->toBeNull()
        ->and($sessionStore->find($rotated->id))->toBeNull();

    $reset = $services->passwordResets()->issue($account->id());
    expect($reset->token)->not->toBeNull()
        ->and($services->passwordResets()->completeWithPlainPassword(
            $reset->token ?? '',
            'reset-secret',
            $hasher,
            $services->passwordPolicy(),
        )->completed())->toBeTrue()
        ->and($services->passwordResets()->complete(
            $reset->token ?? '',
            $hasher->hash('replayed-secret'),
        )->failed())->toBeTrue();

    $passwordless = $services->passwordless()->issue($account->identifier());
    expect($passwordless->token)->not->toBeNull()
        ->and($services->passwordless()->verify($passwordless->token ?? '')->successful())->toBeTrue();

    $remember = $services->rememberMe()->issue($account->id(), 'device-1');
    expect($remember->token)->not->toBeNull()
        ->and($services->rememberMe()->verify($remember->token?->value ?? '')->verified())->toBeTrue();

    $now = time();
    $access = $services->tokens()->issueAccessToken(new AccessTokenClaims(
        subjectId: $account->id(),
        actorId: null,
        issuedAt: $now,
        expiresAt: $now + 300,
        scopes: ['profile.read'],
    ));
    $refresh = $services->tokens()->issueRefreshToken($account->id(), 'web', 'device-1');

    expect($access->token)->not->toBeNull()
        ->and($services->tokens()->verifyAccessToken($access->token ?? '')->successful())->toBeTrue()
        ->and($refresh->token)->not->toBeNull()
        ->and($refresh->refreshToken)->not->toBeNull()
        ->and($services->tokens()->verifyRefreshToken($refresh->token ?? '')->successful())->toBeTrue();

    $refreshRecord = $refresh->refreshToken;
    if (!$refreshRecord instanceof RefreshTokenRecord) {
        throw new LogicException('Expected a refresh token record.');
    }
    $rotation = $services->tokens()->rotateRefreshToken($refreshRecord);

    expect($rotation->successful())->toBeTrue()
        ->and($services->tokens()->revokeRefreshFamily($refreshRecord->familyId)->successful())->toBeTrue()
        ->and($services->tokens()->rotateRefreshToken($rotation->record ?? $refreshRecord)->failed())->toBeTrue();

    $notifier = $app->make(AuthNotifierInterface::class);
    expect($notifier)->toBeInstanceOf(CollectingAuthNotifier::class)
        ->and($notifier->notifications())->not->toBeEmpty();
});

it('locks repeated login failures and supports an explicit unlock', function (): void {
    $app = foundationAuthApplication();
    $services = $app->make(AuthServices::class);
    $account = $services->accounts()->create(
        'locked@example.test',
        $services->passwordHasher()->hash('correct-secret'),
    )->account;
    if (!$account instanceof Account) {
        throw new LogicException('Expected an account.');
    }

    for ($attempt = 0; $attempt < 5; ++$attempt) {
        $services->authenticator()->login(new \Infocyph\Foundation\Auth\Authentication\Login\LoginRequest(
            $account->identifier(),
            'wrong-secret',
        ));
    }

    expect($services->lockouts()->isLocked($account->id()))->toBeTrue()
        ->and($services->authenticator()->login(new \Infocyph\Foundation\Auth\Authentication\Login\LoginRequest(
            $account->identifier(),
            'correct-secret',
        ))->authenticated())->toBeFalse()
        ->and($services->lockouts()->unlock($account->id())->successful())->toBeTrue()
        ->and($services->authenticator()->login(new \Infocyph\Foundation\Auth\Authentication\Login\LoginRequest(
            $account->identifier(),
            'correct-secret',
        ))->authenticated())->toBeTrue();
});

it('composes permissions roles grants gates and denial auditing', function (): void {
    $app = foundationAuthApplication();
    $services = $app->make(AuthServices::class);
    $created = $services->accounts()->create('authorizer@example.test');
    $account = $created->account;

    expect($account)->not->toBeNull();
    if (!$account instanceof Account) {
        throw new LogicException('Expected an account.');
    }

    $principal = new Principal($account->id(), accountId: $account->id());
    $read = $services->permissions()->create('invoice.read');
    $write = $services->permissions()->create('invoice.write');
    $role = $services->roles()->create('billing');

    $services->permissions()->assignToAccount($account->id(), $read->id);
    $services->permissions()->assignToRole($role->id, $write->id);
    $services->roles()->assign($account->id(), $role->id);

    expect($services->authorizer()->can($principal, 'invoice.read')->allowed)->toBeTrue()
        ->and($services->authorizer()->can($principal, 'invoice.write')->allowed)->toBeTrue()
        ->and($services->authorizer()->can($principal, 'invoice.delete')->allowed)->toBeFalse();

    $services->delegation()->grant($principal->id(), 'invoice.delete');
    expect($services->authorizer()->can($principal, 'invoice.delete')->allowed)->toBeTrue();

    $services->gate()->define(
        'invoice.approve',
        static fn(): AuthorizationDecision => AuthorizationDecision::allow('explicit_gate'),
    );
    expect($services->authorizer()->can($principal, 'invoice.approve')->code)->toBe('explicit_gate');

    $audit = $app->make(AuditEventStoreInterface::class);
    expect($audit)->toBeInstanceOf(InMemoryAuditEventStore::class)
        ->and($audit->events())->not->toBeEmpty();
});

it('composes MFA passkeys devices impersonation and step-up state', function (): void {
    $app = foundationAuthApplication();
    $services = $app->make(AuthServices::class);
    $created = $services->accounts()->create('secure@example.test');
    $target = $created->account;

    expect($target)->not->toBeNull();
    if (!$target instanceof Account) {
        throw new LogicException('Expected an account.');
    }

    $device = $services->devices()->register($target->id(), 'Laptop', 'fingerprint')->device;
    expect($device)->not->toBeNull();
    if (!$device instanceof DeviceRecord) {
        throw new LogicException('Expected a device.');
    }

    expect($services->devices()->trust($device->id)->successful())->toBeTrue()
        ->and($services->devices()->touch($device->id)->successful())->toBeTrue()
        ->and($services->devices()->listForAccount($target->id()))->toHaveCount(1);

    $enrollment = $services->mfa()->enrollFactor(
        $target->id(),
        MfaFactorType::TOTP,
        'Primary',
        enabled: true,
    );
    $challenge = $services->mfa()->issueChallenge($target->id(), factorId: $enrollment->factor?->id);

    expect($enrollment->successful())->toBeTrue()
        ->and($challenge->challenge)->not->toBeNull();
    if (!$enrollment->factor instanceof MfaFactor || !$challenge->challenge instanceof MfaChallenge) {
        throw new LogicException('Expected MFA enrollment and challenge state.');
    }

    expect($services->mfa()->verifyChallenge(
        $challenge->challenge->id,
        '000000',
        ['session_id' => 'session-1'],
    )->successful())->toBeTrue()
        ->and($services->mfa()->isSatisfied($target->id(), 'session-1'))->toBeTrue()
        ->and($services->mfa()->verifyRecoveryCode(
            $target->id(),
            $enrollment->recoveryCodes[0] ?? '',
        )->successful())->toBeTrue();

    $registration = $services->passkeys()->startRegistration($target->id());
    if (!$registration->challenge instanceof PasskeyChallenge) {
        throw new LogicException('Expected a passkey registration challenge.');
    }
    $registered = $services->passkeys()->finishRegistration(new PasskeyRegistrationResult(
        challengeId: $registration->challenge->id,
        accountId: $target->id(),
        credentialId: 'credential-1',
        publicKey: 'public-key',
        transports: ['internal'],
    ));
    $authentication = $services->passkeys()->startAuthentication($target->id());
    if (!$authentication->challenge instanceof PasskeyChallenge) {
        throw new LogicException('Expected a passkey authentication challenge.');
    }

    expect($registered->successful())->toBeTrue()
        ->and($services->passkeys()->finishAuthentication(new PasskeyAuthenticationResult(
            challengeId: $authentication->challenge->id,
            credentialId: 'credential-1',
            clientData: 'client-data',
            authenticatorData: 'authenticator-data',
            signature: 'signature',
        ))->successful())->toBeTrue();

    $actor = new Principal('operator', accountId: 'operator');
    $impersonation = $services->impersonation()->startImpersonation($actor, $target);
    expect($impersonation->successful())->toBeTrue();
    if ($impersonation->principal === null || $impersonation->session === null) {
        throw new LogicException('Expected an impersonation session.');
    }

    $stopped = $services->impersonation()->stopImpersonation($impersonation->session);
    expect($impersonation->principal->accountId())->toBe($target->id())
        ->and($stopped->principal?->id())->toBe('operator');

    $oldSession = new AuthSession(
        id: 'old-session',
        accountId: $target->id(),
        deviceId: $device->id,
        createdAt: time() - 3600,
        lastSeenAt: time() - 3600,
        expiresAt: time() + 3600,
        recentAuthAt: time() - 3600,
    );
    expect($services->stepUp()->requiresStepUp($oldSession, 'invoice.approve'))->toBeTrue();

    $services->stepUp()->markSatisfied(
        $oldSession->accountId,
        $oldSession->id,
        'invoice.approve',
    );
    expect($services->stepUp()->requiresStepUp($oldSession, 'invoice.approve'))->toBeFalse()
        ->and($services->devices()->revoke($device->id)->successful())->toBeTrue();
});
