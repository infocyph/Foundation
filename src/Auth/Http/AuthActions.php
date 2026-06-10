<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Http;

use Infocyph\AuthLayer\Account\AccountInterface;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationResult;
use Infocyph\AuthLayer\Authentication\EmailVerification\EmailVerificationStatus;
use Infocyph\AuthLayer\Authentication\Login\LoginRequest;
use Infocyph\AuthLayer\Authentication\Login\LoginResult;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetResult;
use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetStatus;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessResult;
use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessStatus;
use Infocyph\AuthLayer\Contract\Security\PasswordHasherInterface;
use Infocyph\AuthLayer\Contract\Security\PasswordPolicyInterface;
use Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface;
use Infocyph\AuthLayer\Mfa\MfaChallengeResult;
use Infocyph\AuthLayer\Mfa\MfaStatus;
use Infocyph\Foundation\Auth\AuthServices;

final readonly class AuthActions
{
    public function __construct(
        private AuthServices $services,
        private AccountProviderInterface $accounts,
        private PasswordHasherInterface $passwords,
        private PasswordPolicyInterface $policy,
    ) {}

    public function login(array $payload): LoginResult
    {
        return $this->services->authenticator->login(new LoginRequest(
            identifier: $this->string($payload, 'identifier', $this->string($payload, 'email')),
            password: $this->string($payload, 'password'),
            rememberMe: $this->bool($payload, 'remember_me', $this->bool($payload, 'rememberMe')),
            context: $this->context($payload),
        ));
    }

    public function logout(?string $sessionId = null): LogoutResult
    {
        $principal = $this->services->principals->get();
        if ($principal === null) {
            return new LogoutResult(false, sessionId: $sessionId, code: 'no_current_principal');
        }

        $this->services->authenticator->logout($principal, $sessionId);
        $this->services->principals->clear();

        return new LogoutResult(
            true,
            principalId: $principal->id(),
            sessionId: $sessionId,
            code: 'logged_out',
        );
    }

    public function requestEmailVerification(array $payload): EmailVerificationResult
    {
        $account = $this->resolveAccount($payload);
        if ($account === null) {
            return new EmailVerificationResult(
                EmailVerificationStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        $email = $this->string($payload, 'email', $account->identifier());

        return $this->services->emailVerification->issue(
            $account->id(),
            $email,
            $this->context($payload),
        );
    }

    public function requestMfa(array $payload): MfaChallengeResult
    {
        $accountId = $this->accountId($payload);
        if ($accountId === null) {
            return new MfaChallengeResult(
                MfaStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        return $this->services->mfa->issueChallenge(
            accountId: $accountId,
            purpose: $this->string($payload, 'purpose', 'login'),
            factorId: $this->nullableString($payload, 'factor_id', $this->nullableString($payload, 'factorId')),
            context: $this->context($payload),
        );
    }

    public function requestPasswordReset(array $payload): PasswordResetResult
    {
        $account = $this->resolveAccount($payload);
        if ($account === null) {
            return new PasswordResetResult(
                PasswordResetStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        return $this->services->passwordResets->issue(
            $account->id(),
            $this->context($payload),
        );
    }

    public function requestPasswordless(array $payload): PasswordlessResult
    {
        return $this->services->passwordless->issue(
            $this->string($payload, 'identifier', $this->string($payload, 'email')),
            $this->context($payload),
        );
    }

    public function resetPassword(array $payload): PasswordResetResult
    {
        return $this->services->passwordResets->completeWithPlainPassword(
            token: $this->string($payload, 'token'),
            plainPassword: $this->string($payload, 'password', $this->string($payload, 'new_password')),
            hasher: $this->passwords,
            policy: $this->policy,
            context: $this->context($payload),
        );
    }

    public function verifyEmail(array $payload): EmailVerificationResult
    {
        return $this->services->emailVerification->verify(
            $this->string($payload, 'token'),
            $this->context($payload),
        );
    }

    public function verifyMfa(array $payload): MfaChallengeResult
    {
        $challengeId = $this->nullableString($payload, 'challenge_id', $this->nullableString($payload, 'challengeId'));
        $code = $this->string($payload, 'code');

        if ($challengeId !== null) {
            return $this->services->mfa->verifyChallenge(
                $challengeId,
                $code,
                $this->context($payload),
            );
        }

        $accountId = $this->accountId($payload);
        if ($accountId === null) {
            return new MfaChallengeResult(
                MfaStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        return $this->services->mfa->verifyRecoveryCode(
            $accountId,
            $code,
            $this->context($payload),
        );
    }

    public function verifyPasswordless(array $payload): PasswordlessResult
    {
        return $this->services->passwordless->verify(
            $this->string($payload, 'token'),
            $this->context($payload),
        );
    }

    private function accountId(array $payload): ?string
    {
        $direct = $this->nullableString($payload, 'account_id', $this->nullableString($payload, 'accountId'));
        if ($direct !== null) {
            return $direct;
        }

        return $this->resolveAccount($payload)?->id();
    }

    private function bool(array $payload, string $key, bool $default = false): bool
    {
        $value = $payload[$key] ?? null;

        return match (true) {
            is_bool($value) => $value,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            is_int($value) => $value !== 0,
            default => $default,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function context(array $payload): array
    {
        $context = $payload['context'] ?? null;
        if (is_array($context)) {
            return $context;
        }

        $normalized = $payload;
        unset(
            $normalized['account_id'],
            $normalized['accountId'],
            $normalized['challenge_id'],
            $normalized['challengeId'],
            $normalized['code'],
            $normalized['context'],
            $normalized['email'],
            $normalized['factor_id'],
            $normalized['factorId'],
            $normalized['identifier'],
            $normalized['new_password'],
            $normalized['password'],
            $normalized['purpose'],
            $normalized['rememberMe'],
            $normalized['remember_me'],
            $normalized['token'],
        );

        return $normalized;
    }

    private function nullableString(array $payload, string $key, ?string $default = null): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }

    private function resolveAccount(array $payload): ?AccountInterface
    {
        $accountId = $this->nullableString($payload, 'account_id', $this->nullableString($payload, 'accountId'));
        if ($accountId !== null) {
            return $this->accounts->findById($accountId);
        }

        $identifier = $this->nullableString($payload, 'identifier', $this->nullableString($payload, 'email'));

        return $identifier !== null
            ? $this->accounts->findByIdentifier($identifier)
            : null;
    }

    private function string(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
