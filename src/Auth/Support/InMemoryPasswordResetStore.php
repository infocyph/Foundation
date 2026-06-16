<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;
use Infocyph\Foundation\Auth\Contract\Storage\PasswordResetStoreInterface;

final class InMemoryPasswordResetStore extends AbstractInMemoryConsumableStore implements PasswordResetStoreInterface
{
    public function consume(string $requestId): void
    {
        $this->consumeStored($requestId);
    }

    public function find(string $requestId): ?PasswordResetRequest
    {
        $request = $this->findStored($requestId);

        return $request instanceof PasswordResetRequest ? $request : null;
    }

    public function save(PasswordResetRequest $request): void
    {
        $this->saveStored($request, $request->id);
    }

    public function wasConsumed(string $requestId): bool
    {
        return $this->find($requestId)?->isConsumed() ?? false;
    }

    protected function consumeRequest(object $request, int $consumedAt): object
    {
        return $request instanceof PasswordResetRequest ? $request->withConsumedAt($consumedAt) : $request;
    }
}
