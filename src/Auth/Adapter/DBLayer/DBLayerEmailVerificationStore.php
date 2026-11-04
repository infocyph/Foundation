<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\DBLayer\Exceptions\TransactionException;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailDestinationChanged;
use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;

final readonly class DBLayerEmailVerificationStore extends DBLayerConsumableStore implements EmailVerificationStoreInterface
{
    #[\Override]
    public function consume(string $requestId): void
    {
        $this->consumeRequest('emailVerifications', $requestId);
    }

    #[\Override]
    public function consumeAndApply(string $requestId, callable $apply): bool
    {
        try {
            return $this->connection()->transaction(function () use ($requestId, $apply): bool {
                $now = $this->now();
                $updated = $this->query('emailVerifications')
                    ->whereRaw('id = ? AND consumed_at IS NULL AND expires_at > ?', [$requestId, $now])
                    ->update(['consumed_at' => $now]);
                if ($updated !== 1) {
                    return false;
                }

                $apply();

                return true;
            }, attempts: 3) === true;
        } catch (TransactionException $exception) {
            $previous = $exception->getPrevious();
            // DBLayer wraps application failures after rollback. Unwrap this
            // specific trusted-denial signal only when rollback succeeded.
            // Preserve rollback failures and all unrelated transaction errors.
            if ($previous instanceof EmailDestinationChanged
                && $exception->getMessage() === 'Transaction failed: ' . $previous->getMessage()
            ) {
                throw $previous;
            }

            throw $exception;
        }
    }

    #[\Override]
    public function find(string $requestId): ?EmailVerificationRequest
    {
        return $this->findRequest('emailVerifications', $this->mapRequest(...), $requestId);
    }

    #[\Override]
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

    /**
     * @param array<string, mixed> $row
     */
    private function mapRequest(array $row): EmailVerificationRequest
    {
        return new EmailVerificationRequest(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            email: $this->string($row['email'] ?? ''),
            requestedAt: $this->int($row['requested_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
            context: DBLayerJson::decode($row['context'] ?? null),
        );
    }
}
