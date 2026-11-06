<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredential;
use Infocyph\Foundation\Auth\Passkey\PasskeyCredentialCompareAndSwapStoreInterface;

/**
 * Application-selectable in-memory passkey credential store.
 *
 * @api
 */
final class InMemoryPasskeyCredentialStore implements PasskeyCredentialCompareAndSwapStoreInterface
{
    /** @var array<string, PasskeyCredential> */
    private array $credentials = [];

    public function __construct(
        private readonly ClockInterface $clock = new SystemClock(),
    ) {}

    #[\Override]
    public function compareAndSwap(?PasskeyCredential $expected, PasskeyCredential $updated): bool
    {
        $current = $this->credentials[$updated->id] ?? null;

        if ($expected === null) {
            if ($updated->revision !== 0 || $current !== null) {
                return false;
            }

            $this->credentials[$updated->id] = $updated;

            return true;
        }

        if (
            !$current instanceof PasskeyCredential
            || $expected->id !== $updated->id
            || $updated->revision !== $expected->revision + 1
            || $current->revision !== $expected->revision
        ) {
            return false;
        }

        $this->credentials[$updated->id] = $updated;

        return true;
    }

    #[\Override]
    public function findByCredentialId(string $credentialId): ?PasskeyCredential
    {
        foreach ($this->credentials as $credential) {
            if ($credential->credentialId === $credentialId && !$credential->isRevoked()) {
                return $credential;
            }
        }

        return null;
    }

    #[\Override]
    public function findForAccount(string $accountId): array
    {
        return array_values(array_filter(
            $this->credentials,
            static fn(PasskeyCredential $credential): bool => $credential->accountId === $accountId && !$credential->isRevoked(),
        ));
    }

    #[\Override]
    public function revoke(string $credentialId): void
    {
        foreach ($this->credentials as $id => $credential) {
            if (($credential->credentialId === $credentialId || $credential->id === $credentialId) && !$credential->isRevoked()) {
                $this->credentials[$id] = $credential->revokedAt($this->clock->now());
            }
        }
    }

    #[\Override]
    public function save(PasskeyCredential $credential): void
    {
        $this->credentials[$credential->id] = $credential;
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function updateUsage(string $credentialId, int $signCount, int $usedAt): void
    {
        $credential = $this->findByCredentialId($credentialId);
        if (!$credential instanceof PasskeyCredential) {
            return;
        }

        if (!$this->compareAndSwap($credential, $credential->used($signCount, $usedAt))) {
            throw new \RuntimeException('Passkey credential changed concurrently during usage persistence.');
        }
    }
}
