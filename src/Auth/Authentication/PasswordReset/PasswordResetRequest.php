<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\PasswordReset;

use Infocyph\Foundation\Auth\Support\AbstractConsumableRequest;

final readonly class PasswordResetRequest extends AbstractConsumableRequest
{
    public function withConsumedAt(int $consumedAt): self
    {
        return new self(
            id: $this->id,
            accountId: $this->accountId,
            requestedAt: $this->requestedAt,
            expiresAt: $this->expiresAt,
            consumedAt: $consumedAt,
            context: $this->context,
        );
    }
}
