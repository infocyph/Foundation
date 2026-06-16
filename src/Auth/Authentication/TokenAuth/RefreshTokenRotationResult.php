<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

final readonly class RefreshTokenRotationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $rotated,
        public ?string $token = null,
        public ?RefreshTokenRecord $record = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->rotated;
    }
}
