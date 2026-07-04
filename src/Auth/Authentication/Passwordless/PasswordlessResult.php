<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Passwordless;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Support\TracksSuccessfulStatus;

final readonly class PasswordlessResult
{
    use TracksSuccessfulStatus;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasswordlessStatus $status,
        public ?string $token = null,
        public ?TokenVerificationResult $verification = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    /**
     * @return list<\UnitEnum>
     */
    protected function successfulStatuses(): array
    {
        return [PasswordlessStatus::ISSUED, PasswordlessStatus::VERIFIED];
    }
}
