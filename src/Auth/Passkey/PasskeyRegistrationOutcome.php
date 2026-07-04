<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

use Infocyph\Foundation\Auth\Support\TracksSuccessfulStatus;

final readonly class PasskeyRegistrationOutcome
{
    use TracksSuccessfulStatus;

    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public PasskeyRegistrationStatus $status,
        public ?PasskeyChallenge $challenge = null,
        public ?PasskeyCredential $credential = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    /**
     * @return list<\UnitEnum>
     */
    protected function successfulStatuses(): array
    {
        return [PasskeyRegistrationStatus::STARTED, PasskeyRegistrationStatus::REGISTERED];
    }
}
