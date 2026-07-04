<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Mfa;

use Infocyph\Foundation\Auth\Support\TracksSuccessfulStatus;

final readonly class MfaEnrollmentResult
{
    use TracksSuccessfulStatus;

    /**
     * @param list<string> $recoveryCodes
     * @param array<string, mixed> $context
     */
    public function __construct(
        public MfaStatus $status,
        public ?MfaFactor $factor = null,
        public array $recoveryCodes = [],
        public ?string $code = null,
        public array $context = [],
    ) {}

    /**
     * @return list<\UnitEnum>
     */
    protected function successfulStatuses(): array
    {
        return [MfaStatus::ENROLLED, MfaStatus::ACTIVATED, MfaStatus::REMOVED];
    }
}
