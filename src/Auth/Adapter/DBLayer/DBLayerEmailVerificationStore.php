<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;

final readonly class DBLayerEmailVerificationStore extends ClockedDBLayerStore implements EmailVerificationStoreInterface
{
    public function consume(string $requestId): void
    {
        $this->updateWhere('emailVerifications', ['consumed_at' => $this->now()], 'id = ?', [$requestId]);
    }

    public function find(string $requestId): ?EmailVerificationRequest
    {
        return $this->firstMapped(
            sprintf('SELECT * FROM %s WHERE id = ?', $this->table('emailVerifications')),
            /**
             * @param array<string, mixed> $row
             */
            fn(array $row): EmailVerificationRequest => new EmailVerificationRequest(
                id: $this->string($row['id'] ?? ''),
                accountId: $this->string($row['account_id'] ?? ''),
                email: $this->string($row['email'] ?? ''),
                requestedAt: $this->int($row['requested_at'] ?? 0),
                expiresAt: $this->int($row['expires_at'] ?? 0),
                consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
                context: DBLayerJson::decode($row['context'] ?? null),
            ),
            [$requestId],
        );
    }

    public function save(EmailVerificationRequest $request): void
    {
        $this->upsertRecord('emailVerifications', 'id', [
            'id' => $request->id,
            'account_id' => $request->accountId,
            'email' => $request->email,
            'requested_at' => $request->requestedAt,
            'expires_at' => $request->expiresAt,
            'consumed_at' => $request->consumedAt,
            'context' => DBLayerJson::encode($request->context),
        ]);
    }
}
