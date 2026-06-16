<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\RememberMe;

final readonly class RememberToken
{
    public function __construct(
        public string $value,
        public string $selector,
        public string $familyId,
        public string $verifierHash,
        public int $expiresAt,
    ) {}
}
