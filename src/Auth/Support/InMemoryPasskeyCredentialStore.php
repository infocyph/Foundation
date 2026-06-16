<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialStoreInterface;

final class InMemoryPasskeyCredentialStore implements PasskeyCredentialStoreInterface
{
    /**
     * @var array<string, PasskeyCredential>
     */
    private array $credentials = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        foreach ($this->credentials as $credential) {
            if ($credential->credentialId === $credentialId && !$credential->isRevoked()) {
                return $credential;
            }
        }

        return null;
    }

    public function findForAccount(string $accountId): array
    {
        return array_values(array_filter(
            $this->credentials,
            static fn(PasskeyCredential $credential): bool => $credential->accountId === $accountId && !$credential->isRevoked(),
        ));
    }

    public function revoke(string $credentialId): void
    {
        foreach ($this->credentials as $id => $credential) {
            if (($credential->credentialId === $credentialId || $credential->id === $credentialId) && !$credential->isRevoked()) {
                $this->credentials[$id] = $credential->revokedAt($this->clock->now());
            }
        }
    }

    public function save(PasskeyCredential $credential): void
    {
        $this->credentials[$credential->id] = $credential;
    }

    public function updateUsage(string $credentialId, int $signCount, int $usedAt): void
    {
        foreach ($this->credentials as $id => $credential) {
            if ($credential->credentialId !== $credentialId || $credential->isRevoked()) {
                continue;
            }

            $this->credentials[$id] = new PasskeyCredential(
                id: $credential->id,
                accountId: $credential->accountId,
                credentialId: $credential->credentialId,
                publicKey: $credential->publicKey,
                signCount: $signCount,
                transports: $credential->transports,
                createdAt: $credential->createdAt,
                lastUsedAt: $usedAt,
                revokedAt: $credential->revokedAt,
                metadata: $credential->metadata,
            );
        }
    }
}
