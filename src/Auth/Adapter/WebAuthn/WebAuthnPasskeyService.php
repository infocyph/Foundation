<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\WebAuthn;

use Infocyph\Foundation\Auth\Adapter\WebAuthn\Support\Base64Url;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyVerificationResult;
use Infocyph\Foundation\Support\ValueNormalizer;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;

final readonly class WebAuthnPasskeyService implements PasskeyServiceInterface
{
    public function __construct(
        private WebAuthnConfig $config,
        private WebAuthnChallengeStore $challenges,
        private PasskeyCredentialStoreInterface $credentials,
        private AuthIdGeneratorInterface $ids,
        private ClockInterface $clock,
        private WebAuthnPublicKeyOptionsFactory $options,
        private WebAuthnCredentialMapper $mapper,
        private WebAuthnRuntime $runtime,
    ) {}

    public function finishAuthentication(PasskeyAuthenticationResult $result): PasskeyVerificationResult
    {
        $challenge = $this->consumeChallenge($result->challengeId, 'authentication');
        $credentialId = $this->resolveCredentialId($result->metadata, $result->credentialId);

        if ($credentialId === '') {
            return new PasskeyVerificationResult(
                false,
                accountId: $challenge->accountId,
                reason: 'credential_missing',
            );
        }

        $credential = $this->credentials->findByCredentialId($credentialId);

        if ($credential === null || $credential->isRevoked()) {
            return new PasskeyVerificationResult(
                false,
                accountId: $challenge->accountId,
                credentialId: $credentialId,
                reason: 'credential_not_found',
            );
        }

        if ($challenge->accountId !== null && $credential->accountId !== $challenge->accountId) {
            return new PasskeyVerificationResult(
                false,
                accountId: $challenge->accountId,
                credentialId: $credential->credentialId,
                reason: 'account_mismatch',
            );
        }

        try {
            $publicKeyCredential = $this->loadAuthenticationCredential($result);
            if (!$publicKeyCredential->response instanceof AuthenticatorAssertionResponse) {
                throw new WebAuthnException('WebAuthn authentication payload is not an assertion response.');
            }

            $validated = $this->runtime->assertionValidator()->check(
                $this->credentialRecord($credential),
                $publicKeyCredential->response,
                $this->options->authenticationOptions(
                    Base64Url::decode($challenge->challenge),
                    $challenge->allowedCredentialIds,
                ),
                $this->host(),
                $challenge->accountId,
            );
        } catch (AuthenticatorResponseVerificationException) {
            return new PasskeyVerificationResult(
                false,
                accountId: $credential->accountId,
                credentialId: $credential->credentialId,
                reason: 'assertion_invalid',
            );
        } catch (\Throwable $exception) {
            throw new WebAuthnException('WebAuthn assertion verification failed.', 0, $exception);
        }

        return new PasskeyVerificationResult(
            true,
            accountId: $credential->accountId,
            credentialId: $credential->credentialId,
            signCount: $validated->counter,
            context: [
                'challenge' => $challenge->challenge,
                'webauthn' => [
                    'credential_record' => $this->runtime->normalizeCredentialRecord($validated),
                ],
            ],
        );
    }

    public function finishRegistration(PasskeyRegistrationResult $result): PasskeyCredential
    {
        $challenge = $this->consumeChallenge($result->challengeId, 'registration');

        if ($challenge->accountId !== null && $challenge->accountId !== $result->accountId) {
            throw new WebAuthnException('WebAuthn registration account mismatch.');
        }

        try {
            $publicKeyCredential = $this->loadRegistrationCredential($result);
            if (!$publicKeyCredential->response instanceof AuthenticatorAttestationResponse) {
                throw new WebAuthnException('WebAuthn registration payload is not an attestation response.');
            }

            $validated = $this->runtime->attestationValidator()->check(
                $publicKeyCredential->response,
                $this->options->registrationOptions(
                    $result->accountId,
                    Base64Url::decode($challenge->challenge),
                    $this->challengeCredentialIds($challenge, 'exclude_credential_ids'),
                ),
                $this->host(),
            );
        } catch (AuthenticatorResponseVerificationException $exception) {
            throw new WebAuthnException('WebAuthn attestation verification failed.', 0, $exception);
        } catch (\Throwable $exception) {
            throw new WebAuthnException('WebAuthn registration verification failed.', 0, $exception);
        }

        return $this->mapper->fromCredentialRecord(
            $result->accountId,
            $validated,
            $this->registrationMetadata($result->metadata),
        );
    }

    public function startAuthentication(?string $accountId = null): PasskeyChallenge
    {
        $allowedCredentialIds = [];

        if ($accountId !== null) {
            $allowedCredentialIds = $this->activeCredentialIdsForAccount($accountId);
        }

        return $this->createChallenge(
            accountId: $accountId,
            purpose: 'authentication',
            recordMetadata: [],
            metadata: [
                'publicKey' => fn(string $challenge) => $this->options->authentication(
                    Base64Url::decode($challenge),
                    $allowedCredentialIds,
                ),
            ],
            allowedCredentialIds: $allowedCredentialIds,
        );
    }

    public function startRegistration(string $accountId): PasskeyChallenge
    {
        $excludedCredentialIds = $this->activeCredentialIdsForAccount($accountId);

        return $this->createChallenge(
            accountId: $accountId,
            purpose: 'registration',
            recordMetadata: [
                'exclude_credential_ids' => $excludedCredentialIds,
            ],
            metadata: [
                'publicKey' => fn(string $challenge) => $this->options->registration(
                    $accountId,
                    Base64Url::decode($challenge),
                    $excludedCredentialIds,
                ),
            ],
            allowedCredentialIds: [],
        );
    }

    /**
     * @return list<string>
     */
    private function activeCredentialIdsForAccount(string $accountId): array
    {
        $credentialIds = [];

        foreach ($this->credentials->findForAccount($accountId) as $credential) {
            if ($credential->isRevoked()) {
                continue;
            }

            $credentialIds[] = $credential->credentialId;
        }

        return $credentialIds;
    }

    /**
     * @return list<string>
     */
    private function challengeCredentialIds(WebAuthnChallengeRecord $challenge, string $key): array
    {
        $credentialIds = $challenge->metadata[$key] ?? [];
        if (!is_array($credentialIds)) {
            return [];
        }

        return ValueNormalizer::stringList($credentialIds);
    }

    private function consumeChallenge(string $challengeId, string $purpose): WebAuthnChallengeRecord
    {
        $record = $this->challenges->pull($challengeId);
        if (!$record instanceof WebAuthnChallengeRecord) {
            throw new WebAuthnException('WebAuthn challenge was not found or has expired.');
        }

        if ($record->purpose !== $purpose) {
            throw new WebAuthnException('WebAuthn challenge purpose mismatch.');
        }

        if ($record->expiresAt <= $this->clock->now()) {
            throw new WebAuthnException('WebAuthn challenge has expired.');
        }

        return $record;
    }

    /**
     * @param array<string, mixed> $recordMetadata
     * @param array<string, mixed> $metadata
     * @param list<string> $allowedCredentialIds
     */
    private function createChallenge(
        ?string $accountId,
        string $purpose,
        array $recordMetadata,
        array $metadata,
        array $allowedCredentialIds,
    ): PasskeyChallenge {
        $challengeId = $this->ids->challengeId();
        $challenge = Base64Url::random(32);
        $issuedAt = $this->clock->now();
        $expiresAt = $issuedAt + $this->config->challengeTtl;

        $this->challenges->put(new WebAuthnChallengeRecord(
            id: $challengeId,
            accountId: $accountId,
            purpose: $purpose,
            challenge: $challenge,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            allowedCredentialIds: $allowedCredentialIds,
            metadata: $recordMetadata,
        ), $this->config->challengeTtl);

        return new PasskeyChallenge(
            id: $challengeId,
            accountId: $accountId,
            purpose: $purpose,
            challenge: $challenge,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            metadata: [
                'publicKey' => isset($metadata['publicKey']) && $metadata['publicKey'] instanceof \Closure
                    ? $metadata['publicKey']($challenge)
                    : null,
                'webauthn' => [
                    'origin' => $this->config->origin,
                    'rp_id' => $this->config->rpId,
                ],
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function credentialPayloadFromMetadata(array $metadata): array
    {
        $credential = $metadata['credential'] ?? null;

        return ValueNormalizer::associativeArray($credential);
    }

    private function credentialRecord(PasskeyCredential $credential): CredentialRecord
    {
        $payload = $this->storedCredentialRecordPayload($credential);
        $record = $this->runtime->denormalizeCredentialRecord($payload);
        $encodedUserHandle = $payload['userHandle'] ?? null;
        $userHandle = is_string($encodedUserHandle)
            ? Base64Url::decode($encodedUserHandle)
            : '';
        $userHandle = $userHandle !== '' ? $userHandle : $credential->accountId;

        return CredentialRecord::create(
            $this->decodeCredentialId($credential->credentialId),
            $record->type,
            $record->transports !== [] ? $record->transports : $credential->transports,
            $record->attestationType,
            $record->trustPath,
            $record->aaguid,
            $this->decodePublicKey($credential->publicKey),
            $userHandle,
            $credential->signCount,
            $record->otherUI,
            $record->backupEligible,
            $record->backupStatus,
            $record->uvInitialized,
        );
    }

    private function decodeCredentialId(string $credentialId): string
    {
        $decoded = Base64Url::decode($credentialId);

        return $decoded !== '' ? $decoded : $credentialId;
    }

    private function decodePublicKey(string $publicKey): string
    {
        $decoded = Base64Url::decode($publicKey);

        return $decoded !== '' ? $decoded : $publicKey;
    }

    private function host(): string
    {
        if ($this->config->rpId !== null && $this->config->rpId !== '') {
            return $this->config->rpId;
        }

        $host = $this->config->origin !== null
            ? parse_url($this->config->origin, PHP_URL_HOST)
            : null;

        if (is_string($host) && $host !== '') {
            return $host;
        }

        throw new WebAuthnException('WebAuthn host is not configured.');
    }

    private function loadAuthenticationCredential(PasskeyAuthenticationResult $result): PublicKeyCredential
    {
        $payload = $this->credentialPayloadFromMetadata($result->metadata);
        if ($payload === []) {
            throw new WebAuthnException('WebAuthn authentication payload is missing the credential object.');
        }

        return $this->runtime->loadCredential($payload);
    }

    private function loadRegistrationCredential(PasskeyRegistrationResult $result): PublicKeyCredential
    {
        $payload = $this->credentialPayloadFromMetadata($result->metadata);
        if ($payload === []) {
            throw new WebAuthnException(
                'WebAuthn registration requires the full browser credential response.',
            );
        }

        return $this->runtime->loadCredential($payload);
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function registrationMetadata(array $metadata): array
    {
        unset($metadata['credential'], $metadata['credential_json']);

        return $metadata;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function resolveCredentialId(array $metadata, string $fallback): string
    {
        $credential = $this->credentialPayloadFromMetadata($metadata);
        $credentialId = $credential['id'] ?? null;

        return is_string($credentialId) && $credentialId !== ''
            ? $credentialId
            : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function storedCredentialRecordPayload(PasskeyCredential $credential): array
    {
        $webauthn = ValueNormalizer::associativeArray($credential->metadata['webauthn'] ?? null);
        if ($webauthn === []) {
            throw new WebAuthnException('Stored WebAuthn credential metadata is missing.');
        }

        $record = ValueNormalizer::associativeArray($webauthn['credential_record'] ?? null);
        if ($record === []) {
            throw new WebAuthnException('Stored WebAuthn credential record is missing.');
        }

        return $record;
    }
}
