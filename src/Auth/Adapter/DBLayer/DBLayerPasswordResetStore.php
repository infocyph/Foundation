<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\DBLayer;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Contract\Storage\PasswordResetStoreInterface;

final readonly class DBLayerPasswordResetStore extends DBLayerConsumableStore implements PasswordResetStoreInterface
{
    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
    public function consume(string $requestId): void
    {
        $this->consumeRequest('passwordResets', $requestId);
    }

    /**
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \Infocyph\Foundation\Exception\ConfigurationException
     */
    #[\Override]
    public function consumeAndApply(string $requestId, callable $apply): bool
    {
        return $this->connection()->transaction(function () use ($requestId, $apply): bool {
            $now = $this->now();
            $updated = $this->query('passwordResets')
                ->whereRaw('id = ? AND consumed_at IS NULL AND expires_at > ?', [$requestId, $now])
                ->update(['consumed_at' => $now]);
            if ($updated !== 1) {
                return false;
            }

            $apply();

            return true;
        }, attempts: 3) === true;
    }

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function find(string $requestId): ?PasswordResetRequest
    {
        return $this->findRequest('passwordResets', $this->mapRequest(...), $requestId);
    }

    /**
     * @throws \InvalidArgumentException
     * @throws \LogicException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     * @throws \RuntimeException
     */
    #[\Override]
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

    /**
     * @throws \RuntimeException
     */
    #[\Override]
    public function wasConsumed(string $requestId): bool
    {
        return $this->requestWasConsumed('passwordResets', $requestId);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapRequest(array $row): PasswordResetRequest
    {
        return new PasswordResetRequest(
            id: $this->string($row['id'] ?? ''),
            accountId: $this->string($row['account_id'] ?? ''),
            requestedAt: $this->int($row['requested_at'] ?? 0),
            expiresAt: $this->int($row['expires_at'] ?? 0),
            consumedAt: $this->intOrNull($row['consumed_at'] ?? null),
            context: DBLayerJson::decode($row['context'] ?? null),
        );
    }
}
