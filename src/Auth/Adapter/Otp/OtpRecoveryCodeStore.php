<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Otp;

use DateTimeImmutable;
use Infocyph\Foundation\Auth\Mfa\MfaFactor;
use Infocyph\Foundation\Auth\Mfa\MfaFactorCompareAndSwapStoreInterface;
use Infocyph\Foundation\Auth\Mfa\MfaFactorType;
use Infocyph\OTP\Contracts\RecoveryCodeStoreInterface;
use RuntimeException;

final readonly class OtpRecoveryCodeStore implements RecoveryCodeStoreInterface
{
    private const int MAX_CAS_ATTEMPTS = 5;
    private const string METADATA_KEY = 'otp_recovery';

    public function __construct(private MfaFactorCompareAndSwapStoreInterface $factors) {}

    public function consume(string $binding, string $hashedCode, DateTimeImmutable $usedAt): array
    {
        $accountId = $this->accountId($binding);
        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $factor = $this->find($accountId);
            if ($factor === null) {
                return ['consumed' => false, 'total' => 0, 'remaining' => 0, 'lastUsedAt' => null];
            }

            $state = $this->state($factor);
            $index = $this->digestIndex($state['hashes'], $hashedCode);
            if ($index === null) {
                return [
                    'consumed' => false,
                    'total' => $state['total'],
                    'remaining' => count($state['hashes']),
                    'lastUsedAt' => $state['lastUsedAt'],
                ];
            }

            $hashes = $state['hashes'];
            unset($hashes[$index]);
            $hashes = array_values($hashes);
            $updated = $factor->withMetadata($this->withRecoveryMetadata(
                $factor->metadata,
                $hashes,
                $state['total'],
                $state['issuedAt'],
                $usedAt,
            ));
            if ($this->factors->compareAndSwap($factor, $updated)) {
                return [
                    'consumed' => true,
                    'total' => $state['total'],
                    'remaining' => count($hashes),
                    'lastUsedAt' => $usedAt,
                ];
            }
        }

        throw new RuntimeException('Unable to atomically consume recovery state after concurrent updates.');
    }

    public function metadata(string $binding): array
    {
        $factor = $this->find($this->accountId($binding));
        if ($factor === null) {
            return ['total' => 0, 'remaining' => 0, 'lastUsedAt' => null];
        }

        $state = $this->state($factor);

        return [
            'total' => $state['total'],
            'remaining' => count($state['hashes']),
            'lastUsedAt' => $state['lastUsedAt'],
        ];
    }

    public function replace(string $binding, array $hashedCodes, DateTimeImmutable $issuedAt): array
    {
        $accountId = $this->accountId($binding);
        $hashes = $this->hashes($hashedCodes);
        $total = count($hashes);

        for ($attempt = 0; $attempt < self::MAX_CAS_ATTEMPTS; $attempt++) {
            $factor = $this->find($accountId);
            if ($factor === null) {
                $created = new MfaFactor(
                    id: $this->factorId($accountId),
                    accountId: $accountId,
                    type: MfaFactorType::RECOVERY_CODE->value,
                    label: 'Recovery codes',
                    enabled: false,
                    createdAt: $issuedAt->getTimestamp(),
                    metadata: $this->withRecoveryMetadata([], $hashes, $total, $issuedAt, null),
                );
                if ($this->factors->compareAndSwap(null, $created)) {
                    return ['total' => $total, 'remaining' => $total, 'lastUsedAt' => null];
                }
                continue;
            }

            $updated = $factor->withMetadata($this->withRecoveryMetadata(
                $factor->metadata,
                $hashes,
                $total,
                $issuedAt,
                null,
            ));
            if ($this->factors->compareAndSwap($factor, $updated)) {
                return ['total' => $total, 'remaining' => $total, 'lastUsedAt' => null];
            }
        }

        throw new RuntimeException('Unable to atomically replace recovery state after concurrent updates.');
    }

    private function accountId(string $binding): string
    {
        if (!str_starts_with($binding, 'account:')) {
            throw new RuntimeException('Foundation OTP recovery bindings must use account:<id>.');
        }
        $accountId = substr($binding, 8);
        if ($accountId === '') {
            throw new RuntimeException('Foundation OTP recovery account IDs cannot be empty.');
        }

        return $accountId;
    }

    private function digestIndex(array $hashes, string $candidate): ?int
    {
        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $candidate)) {
                return $index;
            }
        }

        return null;
    }

    private function factorId(string $accountId): string
    {
        return 'otp-recovery-' . hash('sha256', $accountId);
    }

    private function find(string $accountId): ?MfaFactor
    {
        $expectedId = $this->factorId($accountId);
        foreach ($this->factors->findForAccount($accountId) as $factor) {
            if ($factor->id === $expectedId) {
                return $factor;
            }
        }

        return null;
    }

    private function hashes(array $hashes): array
    {
        $validated = [];
        foreach ($hashes as $hash) {
            if (!is_string($hash) || strlen($hash) !== 64 || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new RuntimeException('OTP recovery state requires SHA-256 hexadecimal digests.');
            }
            if (isset($validated[$hash])) {
                throw new RuntimeException('OTP recovery digests must be unique.');
            }
            $validated[$hash] = true;
        }

        return array_keys($validated);
    }

    private function state(MfaFactor $factor): array
    {
        $stored = $factor->metadata[self::METADATA_KEY] ?? null;
        if (!is_array($stored)) {
            throw new RuntimeException('Stored OTP recovery state is malformed.');
        }

        $hashes = $this->hashes(is_array($stored['hashes'] ?? null) ? $stored['hashes'] : []);
        $total = $stored['total'] ?? null;
        $issuedAt = $stored['issued_at'] ?? null;
        $lastUsedAt = $stored['last_used_at'] ?? null;
        if (!is_int($total) || $total < count($hashes) || !is_int($issuedAt) || $issuedAt < 0) {
            throw new RuntimeException('Stored OTP recovery metadata is malformed.');
        }
        if ($lastUsedAt !== null && (!is_int($lastUsedAt) || $lastUsedAt < 0)) {
            throw new RuntimeException('Stored OTP recovery timestamp is malformed.');
        }

        return [
            'hashes' => $hashes,
            'issuedAt' => new DateTimeImmutable()->setTimestamp($issuedAt),
            'lastUsedAt' => $lastUsedAt === null ? null : new DateTimeImmutable()->setTimestamp($lastUsedAt),
            'total' => $total,
        ];
    }

    private function withRecoveryMetadata(
        array $metadata,
        array $hashes,
        int $total,
        DateTimeImmutable $issuedAt,
        ?DateTimeImmutable $lastUsedAt,
    ): array {
        $metadata[self::METADATA_KEY] = [
            'hashes' => $hashes,
            'issued_at' => $issuedAt->getTimestamp(),
            'last_used_at' => $lastUsedAt?->getTimestamp(),
            'total' => $total,
        ];

        return $metadata;
    }
}
