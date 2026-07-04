<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Contract\Storage\PasswordResetStoreInterface;

final readonly class DBLayerPasswordResetStore extends ClockedDBLayerStore implements PasswordResetStoreInterface
{
    public function consume(string $requestId): void
    {
        $this->updateWhere('passwordResets', ['consumed_at' => $this->now()], 'id = ?', [$requestId]);
    }

    public function find(string $requestId): ?PasswordResetRequest
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('passwordResets')),
            /**
             * @param array<string, mixed> $row
             */
            fn(array $row): PasswordResetRequest => new PasswordResetRequest(
                id: $this->string($row['id'] ?? ''),
                accountId: $this->string($row['account_id'] ?? ''),
                requestedAt: $this->int($row['requested_at'] ?? 0),
                expiresAt: $this->int($row['expires_at'] ?? 0),
                consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
                context: DBLayerJson::decode($row['context'] ?? null),
            ),
            [$requestId],
        );
    }

    public function save(PasswordResetRequest $request): void
    {
        $this->upsertRecord('passwordResets', 'id', [
            'id' => $request->id,
            'account_id' => $request->accountId,
            'requested_at' => $request->requestedAt,
            'expires_at' => $request->expiresAt,
            'consumed_at' => $request->consumedAt,
            'context' => DBLayerJson::encode($request->context),
        ]);
    }

    public function wasConsumed(string $requestId): bool
    {
        $row = $this->first(
            sprintf('SELECT consumed_at FROM %s WHERE id = ?', $this->table('passwordResets')),
            [$requestId],
        );

        return $row !== null && $this->intOrNull($row['consumed_at'] ?? null) !== null;
    }
}
