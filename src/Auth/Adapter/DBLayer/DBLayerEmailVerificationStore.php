<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;
use Infocyph\Foundation\Database\AuthSchema\AuthTables;
use Infocyph\Foundation\Database\DBLayerFactory;

final readonly class DBLayerEmailVerificationStore extends DBLayerStore implements EmailVerificationStoreInterface
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
            sprintf('UPDATE %s SET consumed_at = ? WHERE id = ?', $this->table('emailVerifications')),
            [$this->clock->now(), $requestId],
        );
    }

    public function find(string $requestId): ?EmailVerificationRequest
    {
        $row = $this->first(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('emailVerifications')),
            [$requestId],
        );

        return $row === null ? null : new EmailVerificationRequest(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            email: $this->string($row['email'] ?? ''),
            requestedAt: $this->int($row['requested_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
            context: DBLayerJson::decode($row['context'] ?? null),
        );
    }

    public function save(EmailVerificationRequest $request): void
    {
        if ($this->find($request->id) !== null) {
            $this->execute(
                sprintf('UPDATE %s SET account_id = ?, email = ?, requested_at = ?, expires_at = ?, consumed_at = ?, context = ? WHERE id = ?', $this->table('emailVerifications')),
                [
                    $request->accountId,
                    $request->email,
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
            sprintf('INSERT INTO %s (id, account_id, email, requested_at, expires_at, consumed_at, context) VALUES (?, ?, ?, ?, ?, ?, ?)', $this->table('emailVerifications')),
            [
                $request->id,
                $request->accountId,
                $request->email,
                $request->requestedAt,
                $request->expiresAt,
                $request->consumedAt,
                DBLayerJson::encode($request->context),
            ],
        );
    }
}
