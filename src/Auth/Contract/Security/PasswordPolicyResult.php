<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Contract\Security;

final readonly class PasswordPolicyResult
{
    /**
     * @param list<string> $violations
     */
    public function __construct(
        public bool $valid,
        public array $violations = [],
        public ?string $code = null,
    ) {}
}
