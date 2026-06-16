<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authorization\Decision;

final readonly class AuthorizationDecision
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public bool $allowed,
        public string $code,
        public ?string $reason = null,
        public array $context = [],
    ) {}

    /**
     * @param array<string, mixed> $context
     */
    public static function allow(
        string $code = 'allowed',
        ?string $reason = null,
        array $context = [],
    ): self {
        return new self(true, $code, $reason, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function deny(
        string $code = 'denied',
        ?string $reason = null,
        array $context = [],
    ): self {
        return new self(false, $code, $reason, $context);
    }
}
