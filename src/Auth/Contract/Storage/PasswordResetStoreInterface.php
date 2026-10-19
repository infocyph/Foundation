<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetRequest;

interface PasswordResetStoreInterface
{
    public function consume(string $requestId): void;

    /** @param callable():void $apply Transactional account mutation; may retry on database contention. */
    public function consumeAndApply(string $requestId, callable $apply): bool;

    public function find(string $requestId): ?PasswordResetRequest;

    public function save(PasswordResetRequest $request): void;

    public function wasConsumed(string $requestId): bool;
}
