<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberTokenRecord;
use Infocyph\Foundation\Auth\Contract\Storage\RememberTokenStoreInterface;

/**
 * Native DBLayer remember-token persistence adapter.
 *
 * @api
 */
final readonly class DBLayerRememberTokenStore extends DBLayerFamilyTokenStore implements RememberTokenStoreInterface
{
    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function find(string $recordId): ?RememberTokenRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('rememberTokens')),
            $this->mapRecord(...),
            [$recordId],
        );
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function findBySelector(string $selector): ?RememberTokenRecord
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE selector = ?', $this->table('rememberTokens')),
            $this->mapRecord(...),
            [$selector],
        );
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function markUsed(string $recordId, int $usedAt): void
    {
        $this->updateWhere('rememberTokens', ['last_used_at' => $usedAt], 'id = ? AND revoked_at IS NULL', [$recordId]);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function revokeFamily(string $familyId): void
    {
        $this->revokeTokenFamily('rememberTokens', $familyId);
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function rotate(string $recordId, RememberTokenRecord $replacement): void
    {
        $current = $this->find($recordId);
        if ($current === null || !$this->rotateIfActive($current, $replacement)) {
            throw new \RuntimeException('Token cannot be rotated more than once.');
        }
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     */
    #[\Override]
    public function rotateIfActive(RememberTokenRecord $current, RememberTokenRecord $replacement): bool
    {
        if ($replacement->id === $current->id
            || $replacement->accountId !== $current->accountId
            || $replacement->familyId !== $current->familyId
        ) {
            return false;
        }

        return $this->rotateTokenIfActive(
            'rememberTokens',
            $current->id,
            $current->accountId,
            $current->familyId,
            'verifier_hash',
            $current->verifierHash,
            fn() => $this->insertRecord('rememberTokens', $this->record($replacement)),
        );
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function save(RememberTokenRecord $record): void
    {
        $this->upsertRecord('rememberTokens', 'id', $this->record($record));
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function wasFamilyRevoked(string $familyId): bool
    {
        return $this->tokenFamilyWasRevoked('rememberTokens', $familyId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRecord(array $row): RememberTokenRecord
    {
        return new RememberTokenRecord(
            deviceId: $this->string($row['device_id'] ?? ''),
            selector: $this->string($row['selector'] ?? ''),
            verifierHash: $this->string($row['verifier_hash'] ?? ''),
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            familyId: $this->string($row['family_id'] ?? ''),
            issuedAt: $this->int($row['issued_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            lastUsedAt: $this->intOrNull($row['last_used_at'] ?? null),
            rotatedAt: $this->intOrNull($row['rotated_at'] ?? null),
            revokedAt: $this->intOrNull($row['revoked_at'] ?? null),
            metadata: DBLayerJson::decode($row['metadata'] ?? null),
        );
    }

    /** @return array<string, mixed> */
    private function record(RememberTokenRecord $record): array
    {
        return [
            'id' => $record->id,
            'account_id' => $record->accountId,
            'device_id' => $record->deviceId,
            'selector' => $record->selector,
            'verifier_hash' => $record->verifierHash,
            'family_id' => $record->familyId,
            'issued_at' => $record->issuedAt,
            'expires_at' => $record->expiresAt,
            'last_used_at' => $record->lastUsedAt,
            'rotated_at' => $record->rotatedAt,
            'revoked_at' => $record->revokedAt,
            'metadata' => DBLayerJson::encode($record->metadata),
        ];
    }
}
