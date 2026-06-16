<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\StepUp;

final readonly class StepUpResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $required,
        public StepUpRequirement $requirement,
        public ?int $satisfiedAt = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return !$this->required;
    }
}
