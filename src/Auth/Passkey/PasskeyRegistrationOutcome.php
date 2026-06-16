<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Passkey;

final readonly class PasskeyRegistrationOutcome
{
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

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->status === PasskeyRegistrationStatus::STARTED
            || $this->status === PasskeyRegistrationStatus::REGISTERED;
    }
}
