<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;
use Infocyph\Foundation\Auth\Contract\Storage\EmailVerificationStoreInterface;

final class InMemoryEmailVerificationStore extends AbstractInMemoryConsumableStore implements EmailVerificationStoreInterface
{
    public function consume(string $requestId): void
    {
        $storedRequestId = $requestId;
        $this->consumeStored($storedRequestId);
    }

    public function consumeAndApply(string $requestId, callable $apply): bool
    {
        $request = $this->find($requestId);
        if ($request === null || $request->isConsumed() || $request->isExpiredAt($this->clock->now())) {
            return false;
        }

        $this->consumeStored($requestId);

        try {
            $apply();
        } catch (\Throwable $exception) {
            $this->saveStored($request, $requestId);

            throw $exception;
        }

        return true;
    }

    public function find(string $requestId): ?EmailVerificationRequest
    {
        $request = $this->findStored($requestId);

        if (!$request instanceof EmailVerificationRequest) {
            return null;
        }

        return $request;
    }

    public function save(EmailVerificationRequest $request): void
    {
        $this->saveStored($request, requestId: $request->id);
    }

    protected function consumeRequest(object $request, int $consumedAt): object
    {
        return $request instanceof EmailVerificationRequest ? $request->withConsumedAt($consumedAt) : $request;
    }
}
