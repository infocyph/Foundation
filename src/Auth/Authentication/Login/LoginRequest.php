<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\Login;

final readonly class LoginRequest
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $identifier,
        public string $password,
        public bool $rememberMe = false,
        public array $context = [],
    ) {}
}
