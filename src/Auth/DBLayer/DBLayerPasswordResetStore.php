<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\DBLayer;

use Infocyph\AuthLayer\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Storage\PasswordResetStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerPasswordResetStore extends DBLayerStore implements PasswordResetStoreInterface
{
    public function __construct(
        DBLayerFactory $db,
        AuthTables $tables,
        private ClockInterface $clock,
        ?string $connection = null,
    ) {
        parent::__construct($db, $tables, $connection);
    }

    public function consume(string $requestId): void
    {
        $this->execute(
            sprintf('UPDATE %s SET consumed_at = ? WHERE id = ?', $this->table('passwordResets')),
            [$this->clock->now(), $requestId],
        );
    }

    public function find(string $requestId): ?PasswordResetRequest
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('passwordResets')),
            [$requestId],
        );

        return $row === null ? null : new PasswordResetRequest(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            requestedAt: $this->int($row['requested_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
            context: DBLayerJson::decode($row['context'] ?? null),
        );
    }

    public function save(PasswordResetRequest $request): void
    {
        if ($this->find($request->id) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, requested_at = ?, expires_at = ?, consumed_at = ?, context = ? WHERE id = ?', $this->table('passwordResets')),
                [
                    $request->accountId,
                    $request->requestedAt,
                    $request->expiresAt,
                    $request->consumedAt,
                    DBLayerJson::encode($request->context),
                    $request->id,
                ],
            );

            return;
        }

        $this->execute(
            sprintf('INSERT INTO %s (id, account_id, requested_at, expires_at, consumed_at, context) VALUES (?, ?, ?, ?, ?, ?)', $this->table('passwordResets')),
            [
                $request->id,
                $request->accountId,
                $request->requestedAt,
                $request->expiresAt,
                $request->consumedAt,
                DBLayerJson::encode($request->context),
            ],
        );
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
