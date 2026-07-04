<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

use Infocyph\Foundation\Auth\Support\TracksSuccessfulStatus;

final readonly class RememberMeResult
{
    use TracksSuccessfulStatus;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public RememberTokenStatus $status,
        public ?RememberToken $token = null,
        public ?RememberTokenRecord $record = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function verified(): bool
    {
        return $this->status === RememberTokenStatus::VERIFIED;
    }

    /**
     * @return list<\UnitEnum>
     */
    protected function successfulStatuses(): array
    {
        return [
            RememberTokenStatus::ISSUED,
            RememberTokenStatus::ROTATED,
            RememberTokenStatus::VERIFIED,
            RememberTokenStatus::REVOKED,
        ];
    }
}
