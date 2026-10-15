<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Storage;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationRequest;

interface EmailVerificationStoreInterface
{
    public function consume(string $requestId): void;

    /** @param callable():void $apply Mutates the account in the same persistence unit. */
    public function consumeAndApply(string $requestId, callable $apply): bool;

    public function find(string $requestId): ?EmailVerificationRequest;

    public function save(EmailVerificationRequest $request): void;
}
