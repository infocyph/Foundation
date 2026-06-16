<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyAuthenticationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyChallenge;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyRegistrationResult;
use Infocyph\Foundation\Auth\Passkey\PasskeyServiceInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyVerificationResult;

final class InMemoryPasskeyService implements PasskeyServiceInterface
{
    /**
     * @var array<string, PasskeyChallenge>
     */
    private array $challenges = [];

    public function __construct(
        private readonly PasskeyCredentialStoreInterface $credentials,
        private readonly ClockInterface $clock,
        private readonly int $ttlSeconds = 300,
    ) {}

    public function finishAuthentication(PasskeyAuthenticationResult $result): PasskeyVerificationResult
    {
        $challenge = $this->consumeChallenge($result->challengeId, 'authentication');
        if ($challenge === null) {
            return new PasskeyVerificationResult(false, reason: 'passkey_challenge_invalid');
        }

        $credential = $this->credentials->findByCredentialId($result->credentialId);
        if ($credential === null || $credential->isRevoked()) {
            return new PasskeyVerificationResult(false, reason: 'passkey_credential_not_found');
        }

        if ($challenge->accountId !== null && $credential->accountId !== $challenge->accountId) {
            return new PasskeyVerificationResult(false, reason: 'passkey_account_mismatch');
        }

        return new PasskeyVerificationResult(
            verified: true,
            accountId: $credential->accountId,
            credentialId: $credential->credentialId,
            signCount: $credential->signCount + 1,
        );
    }

    public function finishRegistration(PasskeyRegistrationResult $result): PasskeyCredential
    {
        $challenge = $this->consumeChallenge($result->challengeId, 'registration');
        if ($challenge === null || $challenge->accountId !== $result->accountId) {
            throw new \RuntimeException('Passkey registration challenge is invalid or expired.');
        }

        return new PasskeyCredential(
            id: bin2hex(random_bytes(16)),
            accountId: $result->accountId,
            credentialId: $result->credentialId,
            publicKey: $result->publicKey,
            signCount: $result->signCount,
            transports: $result->transports,
            createdAt: $this->clock->now(),
            metadata: $result->metadata,
        );
    }

    public function startAuthentication(?string $accountId = null): PasskeyChallenge
    {
        return $this->storeChallenge($accountId, 'authentication');
    }

    public function startRegistration(string $accountId): PasskeyChallenge
    {
        return $this->storeChallenge($accountId, 'registration');
    }

    private function consumeChallenge(string $challengeId, string $purpose): ?PasskeyChallenge
    {
        $challenge = $this->challenges[$challengeId] ?? null;
        unset($this->challenges[$challengeId]);

        if ($challenge === null || $challenge->purpose !== $purpose || $challenge->isExpiredAt($this->clock->now())) {
            return null;
        }

        return $challenge;
    }

    private function storeChallenge(?string $accountId, string $purpose): PasskeyChallenge
    {
        $now = $this->clock->now();
        $challenge = new PasskeyChallenge(
            id: bin2hex(random_bytes(16)),
            accountId: $accountId,
            purpose: $purpose,
            challenge: bin2hex(random_bytes(24)),
            issuedAt: $now,
            expiresAt: $now + $this->ttlSeconds,
        );

        $this->challenges[$challenge->id] = $challenge;

        return $challenge;
    }
}
