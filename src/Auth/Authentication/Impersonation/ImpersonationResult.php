<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Impersonation;

use Infocyph\Foundation\Auth\Principal\PrincipalInterface;

final readonly class ImpersonationResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public ?PrincipalInterface $principal = null,
        public ?ImpersonationSession $session = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        return $this->principal !== null && $this->session !== null;
    }
}
