<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\EmailVerification;

use Infocyph\Foundation\Auth\Support\AbstractConsumableRequest;

final readonly class EmailVerificationRequest extends AbstractConsumableRequest
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $id,
        string $accountId,
        public string $email,
        int $requestedAt,
        int $expiresAt,
        ?int $consumedAt = null,
        array $context = [],
    ) {
        parent::__construct($id, $accountId, $requestedAt, $expiresAt, $consumedAt, $context);
    }

    public function withConsumedAt(int $consumedAt): self
    {
        return new self(
            email: $this->email,
            id: $this->id,
            accountId: $this->accountId,
            requestedAt: $this->requestedAt,
            expiresAt: $this->expiresAt,
            consumedAt: $consumedAt,
            context: $this->context,
        );
    }
}
