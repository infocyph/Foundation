<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Login;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Account\AccountStatus;
use Infocyph\Foundation\Auth\Audit\AuthEventSeverity;
use Infocyph\Foundation\Auth\Audit\AuthEventType;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutManager;
use Infocyph\Foundation\Auth\Authentication\Lockout\LockoutStatus;
use Infocyph\Foundation\Auth\Authentication\Session\SessionManager;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Auth\Principal\Principal;
use Infocyph\Foundation\Auth\Principal\PrincipalInterface;
use Infocyph\Foundation\Auth\Principal\PrincipalType;
use Infocyph\Foundation\Auth\Support\AuthEventRecorder;
use Infocyph\Foundation\Auth\Support\ContextValue;
use Infocyph\Foundation\Auth\Support\SystemClock;

final readonly class Authenticator implements AuthenticatorInterface
{
    public function __construct(
        private AccountProviderInterface $accounts,
        private AccountStoreInterface $accountStore,
        private PasswordVerifierInterface $passwords,
        private SessionManager $sessions,
        private AuthIdGeneratorInterface $ids,
        private AuditEventStoreInterface $audit,
        private LockoutManager $lockouts,
        private ClockInterface $clock = new SystemClock(),
    ) {}

    public function login(LoginRequest $request): LoginResult
    {
        $account = $this->accounts->findByIdentifier($request->identifier);

        if ($account === null) {
            return $this->failure(LoginStatus::INVALID_CREDENTIALS, null, 'invalid_credentials', $request->context);
        }

        if ($this->lockouts->isLocked($account->id()) || $account->status() === AccountStatus::LOCKED) {
            return $this->failure(LoginStatus::ACCOUNT_LOCKED, $account, 'account_locked', $request->context);
        }

        $statusResult = $this->guardStatus($account, $request->context);

        if ($statusResult !== null) {
            return $statusResult;
        }

        $hash = $account->passwordHash();

        if ($hash === null) {
            return $this->failure(LoginStatus::INVALID_CREDENTIALS, $account, 'password_not_configured', $request->context);
        }

        $verification = $this->passwords->verify($request->password, $hash);

        if (!$verification->verified) {
            $lockout = $this->lockouts->recordLoginFailure($account->id(), $request->context);

            return $this->failure(
                $lockout->status === LockoutStatus::LOCKED ? LoginStatus::ACCOUNT_LOCKED : LoginStatus::INVALID_CREDENTIALS,
                $account,
                $lockout->code ?? 'invalid_credentials',
                $request->context,
            );
        }

        $this->lockouts->clearFailures($account->id());

        if ($verification->needsRehash && $verification->rehash !== null) {
            $this->accountStore->updatePasswordHash($account->id(), $verification->rehash);
        }

        $principal = new Principal($account->id(), PrincipalType::ACCOUNT, $account->id(), $account->metadata());
        $session = $this->sessions->create($account->id(), ContextValue::stringOrNull($request->context, 'device_id'), $request->context);

        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            AuthEventType::LOGIN_SUCCESS,
            $account->id(),
            actorId: $principal->id(),
            metadata: $request->context,
            deviceId: $session->deviceId,
            sessionId: $session->id,
        );

        return new LoginResult(LoginStatus::AUTHENTICATED, $principal, $session, 'authenticated', $verification->needsRehash, $request->context);
    }

    public function logout(PrincipalInterface $principal, ?string $sessionId = null): void
    {
        if ($sessionId !== null) {
            $this->sessions->revoke($sessionId);
        } elseif ($principal->accountId() !== null) {
            $this->sessions->revokeAllForAccount($principal->accountId());
        }

        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            AuthEventType::LOGOUT,
            $principal->accountId(),
            actorId: $principal->id(),
            sessionId: $sessionId,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function failure(LoginStatus $status, ?AccountInterface $account, string $code, array $context): LoginResult
    {
        AuthEventRecorder::record(
            $this->audit,
            $this->ids,
            $this->clock,
            AuthEventType::LOGIN_FAILURE,
            $account?->id(),
            metadata: ['code' => $code] + $context,
            severity: AuthEventSeverity::WARNING,
            deviceId: ContextValue::stringOrNull($context, 'device_id'),
        );

        return new LoginResult($status, code: $code, context: $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function guardStatus(AccountInterface $account, array $context): ?LoginResult
    {
        return match ($account->status()) {
            AccountStatus::DISABLED, AccountStatus::SUSPENDED => $this->failure(LoginStatus::ACCOUNT_DISABLED, $account, 'account_disabled', $context),
            AccountStatus::PENDING_VERIFICATION => $this->failure(LoginStatus::EMAIL_VERIFICATION_REQUIRED, $account, 'email_verification_required', $context),
            AccountStatus::PASSWORD_CHANGE_REQUIRED => $this->failure(LoginStatus::PASSWORD_CHANGE_REQUIRED, $account, 'password_change_required', $context),
            AccountStatus::MFA_ENROLLMENT_REQUIRED => $this->failure(LoginStatus::MFA_REQUIRED, $account, 'mfa_required', $context),
            default => null,
        };
    }
}
