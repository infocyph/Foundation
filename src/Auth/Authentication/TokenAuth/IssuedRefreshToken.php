<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $value,
        public string $tokenHash,
        public string $tokenId,
        public string $familyId,
        public int $expiresAt,
    ) {}
}
