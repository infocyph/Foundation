<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Http;

use Infocyph\Foundation\Auth\Account\AccountInterface;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationResult;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationStatus;
use Infocyph\Foundation\Auth\Authentication\Login\LoginRequest;
use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessResult;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetResult;
use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetStatus;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Mfa\MfaChallengeResult;
use Infocyph\Foundation\Auth\Mfa\MfaStatus;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationOutcome;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationOutcome;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationStatus;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class AuthActions
{
    public function __construct(
        private AuthServices $services,
        private AccountProviderInterface $accounts,
        private PasswordHasherInterface $passwords,
        private PasswordPolicyInterface $policy,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function finishPasskeyAuthentication(array $payload): PasskeyAuthenticationOutcome
    {
        return $this->services->passkeys->finishAuthentication(
            $this->passkeyAuthenticationResult($payload),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function finishPasskeyRegistration(array $payload): PasskeyRegistrationOutcome
    {
        $accountId = $this->accountId($payload);
        if ($accountId === null) {
            return new PasskeyRegistrationOutcome(
                PasskeyRegistrationStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        return $this->services->passkeys->finishRegistration(
            $this->passkeyRegistrationResult($payload, $accountId),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    public function requestPasswordless(array $payload): PasswordlessResult
    {
        return $this->services->passwordless->issue(
            $this->string($payload, 'identifier', $this->string($payload, 'email')),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    public function startPasskeyAuthentication(array $payload): PasskeyAuthenticationOutcome
    {
        return $this->services->passkeys->startAuthentication(
            $this->accountId($payload),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function startPasskeyRegistration(array $payload): PasskeyRegistrationOutcome
    {
        $accountId = $this->accountId($payload);
        if ($accountId === null) {
            return new PasskeyRegistrationOutcome(
                PasskeyRegistrationStatus::INVALID,
                code: 'account_not_found',
                context: $this->context($payload),
            );
        }

        return $this->services->passkeys->startRegistration(
            $accountId,
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyEmail(array $payload): EmailVerificationResult
    {
        return $this->services->emailVerification->verify(
            $this->string($payload, 'token'),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    public function verifyPasswordless(array $payload): PasswordlessResult
    {
        return $this->services->passwordless->verify(
            $this->string($payload, 'token'),
            $this->context($payload),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function accountId(array $payload): ?string
    {
        $direct = $this->nullableString($payload, 'account_id', $this->nullableString($payload, 'accountId'));
        if ($direct !== null) {
            return $direct;
        }

        return $this->resolveAccount($payload)?->id();
    }

    private function base64RawId(string $credentialId): string
    {
        if ($credentialId === '') {
            return '';
        }

        $decoded = base64_decode(strtr($credentialId, '-_', '+/'), true);
        if (!is_string($decoded)) {
            return $credentialId;
        }

        return base64_encode($decoded);
    }

    /**
     * @param array<string, mixed> $payload
     */
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
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function context(array $payload): array
    {
        $context = ValueNormalizer::associativeArray($payload['context'] ?? null);
        if ($context !== []) {
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
            $normalized['public_key'],
            $normalized['publicKey'],
            $normalized['rememberMe'],
            $normalized['remember_me'],
            $normalized['attestation_object'],
            $normalized['attestationObject'],
            $normalized['signature'],
            $normalized['token'],
            $normalized['transports'],
            $normalized['user_handle'],
            $normalized['userHandle'],
            $normalized['raw_id'],
            $normalized['rawId'],
            $normalized['credential'],
            $normalized['credential_id'],
            $normalized['credentialId'],
            $normalized['challenge_id'],
            $normalized['challengeId'],
            $normalized['client_data'],
            $normalized['clientData'],
            $normalized['authenticator_data'],
            $normalized['authenticatorData'],
        );

        return $normalized;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function credentialPayload(array $payload): array
    {
        return ValueNormalizer::associativeArray($payload['credential'] ?? null);
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function credentialResponseNullableString(array $credential, string $key, ?string $default = null): ?string
    {
        $response = $credential['response'] ?? null;
        if (is_array($response)) {
            $value = $response[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $credential
     */
    private function credentialResponseString(array $credential, string $key, string $default = ''): string
    {
        $response = $credential['response'] ?? null;
        if (is_array($response)) {
            $value = $response[$key] ?? null;
            if (is_string($value)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $credential
     * @param list<string> $default
     * @return list<string>
     */
    private function credentialResponseStringList(array $credential, string $key, array $default = []): array
    {
        $response = $credential['response'] ?? null;
        if (is_array($response) && is_array($response[$key] ?? null)) {
            return ValueNormalizer::stringList($response[$key]);
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function int(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function intValue(array $payload, string $key, int $default = 0): int
    {
        $value = $payload[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nullableArrayString(array $payload, string $key, ?string $default = null): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function nullableString(array $payload, string $key, ?string $default = null): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && $value !== ''
            ? $value
            : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function passkeyAuthenticationResult(array $payload): PasskeyAuthenticationResult
    {
        $credential = $this->passkeyCredentialPayload($payload);

        return new PasskeyAuthenticationResult(
            challengeId: $this->stringValue($credential, 'challenge_id', $this->stringValue($credential, 'challengeId', $this->string($payload, 'challenge_id', $this->string($payload, 'challengeId')))),
            credentialId: $this->stringValue($credential, 'id', $this->stringValue($credential, 'credential_id', $this->stringValue($credential, 'credentialId', $this->string($payload, 'credential_id', $this->string($payload, 'credentialId'))))),
            clientData: $this->credentialResponseString($credential, 'clientDataJSON', $this->string($payload, 'client_data', $this->string($payload, 'clientData'))),
            authenticatorData: $this->credentialResponseString($credential, 'authenticatorData', $this->string($payload, 'authenticator_data', $this->string($payload, 'authenticatorData'))),
            signature: $this->credentialResponseString($credential, 'signature', $this->string($payload, 'signature')),
            userHandle: $this->credentialResponseNullableString($credential, 'userHandle', $this->nullableString($payload, 'user_handle', $this->nullableString($payload, 'userHandle'))),
            metadata: $this->passkeyMetadata($payload, $credential),
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function passkeyCredentialPayload(array $payload): array
    {
        $credential = $this->credentialPayload($payload);
        $response = ValueNormalizer::associativeArray($credential['response'] ?? null);

        $id = $this->stringValue(
            $credential,
            'id',
            $this->stringValue(
                $credential,
                'credential_id',
                $this->stringValue(
                    $credential,
                    'credentialId',
                    $this->string($payload, 'credential_id', $this->string($payload, 'credentialId')),
                ),
            ),
        );
        $rawId = $this->stringValue(
            $credential,
            'rawId',
            $this->stringValue(
                $credential,
                'raw_id',
                $this->string($payload, 'raw_id', $this->string($payload, 'rawId', $this->base64RawId($id))),
            ),
        );

        $clientData = $this->string($payload, 'client_data', $this->string($payload, 'clientData'));
        $clientData = $this->stringValue($credential, 'clientData', $clientData);
        $clientData = $this->stringValue($credential, 'client_data', $clientData);
        $clientData = $this->stringValue($credential, 'clientDataJSON', $clientData);
        $clientData = $this->stringValue($response, 'clientData', $clientData);
        $clientData = $this->stringValue($response, 'client_data', $clientData);
        $response['clientDataJSON'] = $this->stringValue($response, 'clientDataJSON', $clientData);

        $authenticatorData = $this->string($payload, 'authenticator_data', $this->string($payload, 'authenticatorData'));
        $authenticatorData = $this->stringValue($credential, 'authenticator_data', $authenticatorData);
        $authenticatorData = $this->stringValue($credential, 'authenticatorData', $authenticatorData);
        $response['authenticatorData'] = $this->stringValue($response, 'authenticatorData', $authenticatorData);

        $signature = $this->string($payload, 'signature');
        $signature = $this->stringValue($credential, 'signature', $signature);
        $response['signature'] = $this->stringValue($response, 'signature', $signature);

        $userHandle = $this->nullableString($payload, 'user_handle', $this->nullableString($payload, 'userHandle'));
        $userHandle = $this->nullableArrayString($credential, 'user_handle', $userHandle);
        $userHandle = $this->nullableArrayString($credential, 'userHandle', $userHandle);
        $userHandle = $this->nullableArrayString($response, 'user_handle', $userHandle);
        $response['userHandle'] = $this->nullableArrayString($response, 'userHandle', $userHandle);

        $attestationObject = $this->string($payload, 'attestation_object', $this->string($payload, 'attestationObject'));
        $attestationObject = $this->stringValue($credential, 'attestation_object', $attestationObject);
        $attestationObject = $this->stringValue($credential, 'attestationObject', $attestationObject);
        $attestationObject = $this->stringValue($response, 'attestation_object', $attestationObject);
        $response['attestationObject'] = $this->stringValue($response, 'attestationObject', $attestationObject);

        if (!isset($response['transports'])) {
            $response['transports'] = $credential['transports'] ?? $payload['transports'] ?? [];
        }

        $credential['id'] = $id;
        $credential['rawId'] = $rawId;
        $credential['type'] = $this->stringValue($credential, 'type', 'public-key');
        $credential['response'] = $response;

        return $credential;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $credential
     * @return array<string, mixed>
     */
    private function passkeyMetadata(array $payload, array $credential): array
    {
        $metadata = $this->context($payload);
        $metadata['credential'] = $credential;

        return $metadata;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function passkeyRegistrationResult(array $payload, string $accountId): PasskeyRegistrationResult
    {
        $credential = $this->passkeyCredentialPayload($payload);

        return new PasskeyRegistrationResult(
            challengeId: $this->stringValue($credential, 'challenge_id', $this->stringValue($credential, 'challengeId', $this->string($payload, 'challenge_id', $this->string($payload, 'challengeId')))),
            accountId: $accountId,
            credentialId: $this->stringValue($credential, 'id', $this->stringValue($credential, 'credential_id', $this->stringValue($credential, 'credentialId', $this->string($payload, 'credential_id', $this->string($payload, 'credentialId'))))),
            publicKey: $this->stringValue($credential, 'public_key', $this->stringValue($credential, 'publicKey', $this->string($payload, 'public_key', $this->string($payload, 'publicKey')))),
            transports: $this->credentialResponseStringList($credential, 'transports', ValueNormalizer::stringList($credential['transports'] ?? $payload['transports'] ?? [])),
            signCount: $this->intValue($credential, 'sign_count', $this->intValue($credential, 'signCount', $this->int($payload, 'sign_count', $this->int($payload, 'signCount', 0)))),
            metadata: $this->passkeyMetadata($payload, $credential),
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
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

    /**
     * @param array<string, mixed> $payload
     */
    private function string(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key, string $default = ''): string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : $default;
    }
}
