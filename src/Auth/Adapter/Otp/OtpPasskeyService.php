<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyVerificationResult;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\OTP\Passkey;
use Infocyph\OTP\Result\PasskeyResult;
use Infocyph\OTP\ValueObjects\PasskeyCeremony;

final readonly class OtpPasskeyService implements PasskeyServiceInterface
{
    private const string AUTHENTICATION_BINDING = 'foundation:passkey:authentication:v1';

    public function __construct(
        private Passkey $passkey,
        private PasskeyCredentialStoreInterface $credentials,
        private AccountProviderInterface $accounts,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock,
    ) {}

    public function finishAuthentication(PasskeyAuthenticationResult $result): PasskeyVerificationResult
    {
        $credentialJson = $this->credentialJson($result->metadata);
        $credentialId = $this->passkey->extractCredentialId($credentialJson);
        $credential = $this->credentials->findByCredentialId($credentialId);

        if (!$credential instanceof PasskeyCredential || $credential->isRevoked()) {
            return new PasskeyVerificationResult(
                false,
                credentialId: $credentialId,
                reason: 'credential_not_found',
            );
        }

        $verification = $this->passkey->finishAuthentication(
            binding: self::AUTHENTICATION_BINDING,
            ceremonyId: $result->challengeId,
            credentialRecordJson: $this->credentialRecordJson($credential),
            credentialJson: $credentialJson,
            now: $this->clock->now(),
        );

        if (!$verification->matched) {
            return $this->verificationFailure($credential, $verification);
        }

        return new PasskeyVerificationResult(
            true,
            accountId: $credential->accountId,
            credentialId: $verification->credentialId,
            context: [
                'otp_reason' => $verification->reason->value,
                'user_handle' => $verification->userHandle,
                'replay_detected' => $verification->replayDetected,
            ],
            credentialRecordJson: $verification->credentialRecordJson,
            expectedRevision: $credential->revision,
        );
    }

    public function finishRegistration(PasskeyRegistrationResult $result): PasskeyCredential
    {
        $verification = $this->passkey->finishRegistration(
            binding: $this->registrationBinding($result->accountId),
            ceremonyId: $result->challengeId,
            credentialJson: $this->credentialJson($result->metadata),
            now: $this->clock->now(),
        );

        if (!$verification->matched || $verification->credentialId === null || $verification->credentialRecordJson === null) {
            throw new \RuntimeException(sprintf(
                'Passkey registration failed: %s.',
                $verification->reason->value,
            ));
        }

        $metadata = $result->metadata;
        unset($metadata['credential'], $metadata['credential_json']);
        $metadata['otp_passkey'] = ['user_handle' => $verification->userHandle];

        return new PasskeyCredential(
            id: $this->ids->credentialId(),
            accountId: $result->accountId,
            credentialId: $verification->credentialId,
            credentialRecordJson: $verification->credentialRecordJson,
            publicKey: '',
            signCount: 0,
            transports: $result->transports,
            createdAt: $this->clock->now(),
            metadata: $metadata,
        );
    }

    public function startAuthentication(?string $accountId = null): PasskeyChallenge
    {
        $records = [];
        $userHandle = null;

        if ($accountId !== null) {
            $records = $this->activeCredentialRecords($accountId);
            $userHandle = $this->userHandle($accountId);
        }

        $now = $this->clock->now();
        $ceremony = $this->passkey->beginAuthentication(
            binding: self::AUTHENTICATION_BINDING,
            credentialRecordsJson: $records,
            userHandle: $userHandle,
            now: $now,
        );

        return $this->challenge($ceremony, $accountId, $now);
    }

    public function startRegistration(string $accountId): PasskeyChallenge
    {
        $account = $this->accounts->findById($accountId);
        if ($account === null) {
            throw new \RuntimeException('Passkey registration account was not found.');
        }

        $metadata = $account->metadata();
        $displayName = $metadata['name'] ?? null;
        if (!is_string($displayName) || trim($displayName) === '') {
            $displayName = $account->identifier();
        }

        $now = $this->clock->now();
        $ceremony = $this->passkey->beginRegistration(
            binding: $this->registrationBinding($accountId),
            userHandle: $this->userHandle($accountId),
            username: $account->identifier(),
            displayName: $displayName,
            existingCredentialRecordsJson: $this->activeCredentialRecords($accountId),
            now: $now,
        );

        return $this->challenge($ceremony, $accountId, $now);
    }

    /** @return list<string> */
    private function activeCredentialRecords(string $accountId): array
    {
        $records = [];

        foreach ($this->credentials->findForAccount($accountId) as $credential) {
            if ($credential->isRevoked()) {
                continue;
            }

            $records[] = $this->credentialRecordJson($credential);
        }

        return $records;
    }

    private function challenge(PasskeyCeremony $ceremony, ?string $accountId, int $issuedAt): PasskeyChallenge
    {
        $options = json_decode($ceremony->optionsJson, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($options)) {
            throw new \RuntimeException('OTP returned invalid passkey ceremony options.');
        }

        $challenge = $options['challenge'] ?? '';

        return new PasskeyChallenge(
            id: $ceremony->id,
            accountId: $accountId,
            purpose: $ceremony->type,
            challenge: is_string($challenge) ? $challenge : '',
            issuedAt: $issuedAt,
            expiresAt: $ceremony->expiresAt,
            metadata: [
                'publicKey' => $options,
                'otp_passkey' => true,
            ],
        );
    }

    /** @param array<string, mixed> $metadata */
    private function credentialJson(array $metadata): string
    {
        $json = $metadata['credential_json'] ?? null;
        if (is_string($json) && $json !== '') {
            return $json;
        }

        $credential = ValueNormalizer::associativeArray($metadata['credential'] ?? null);
        if ($credential === []) {
            throw new \InvalidArgumentException('Passkey verification requires the full browser credential payload.');
        }

        return json_encode($credential, JSON_THROW_ON_ERROR);
    }

    private function credentialRecordJson(PasskeyCredential $credential): string
    {
        if (is_string($credential->credentialRecordJson) && $credential->credentialRecordJson !== '') {
            return $credential->credentialRecordJson;
        }

        $otp = ValueNormalizer::associativeArray($credential->metadata['otp_passkey'] ?? null);
        $record = $otp['credential_record_json'] ?? null;
        if (is_string($record) && $record !== '') {
            return $record;
        }

        $legacy = ValueNormalizer::associativeArray($credential->metadata['webauthn'] ?? null);
        $legacyRecord = ValueNormalizer::associativeArray($legacy['credential_record'] ?? null);
        if ($legacyRecord !== []) {
            return json_encode($legacyRecord, JSON_THROW_ON_ERROR);
        }

        throw new \RuntimeException('Stored passkey credential is missing the authoritative OTP credential record.');
    }

    private function registrationBinding(string $accountId): string
    {
        return 'foundation:passkey:registration:v1:' . hash('sha256', $accountId);
    }

    private function userHandle(string $accountId): string
    {
        return hash('sha256', "foundation:passkey:user:v1\0" . $accountId, true);
    }

    private function verificationFailure(PasskeyCredential $credential, PasskeyResult $result): PasskeyVerificationResult
    {
        return new PasskeyVerificationResult(
            false,
            accountId: $credential->accountId,
            credentialId: $credential->credentialId,
            reason: $result->replayDetected ? 'passkey_replayed' : 'passkey_invalid',
            context: [
                'otp_reason' => $result->reason->value,
                'replay_detected' => $result->replayDetected,
            ],
        );
    }
}
