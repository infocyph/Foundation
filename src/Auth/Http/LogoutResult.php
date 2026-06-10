<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Http;

final readonly class LogoutResult
{
    public function __construct(
        public bool $loggedOut,
        public ?string $principalId = null,
        public ?string $sessionId = null,
        public ?string $code = null,
    ) {}
}
