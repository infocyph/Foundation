<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Authentication\TokenAuth;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class TokenAuthResult
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public TokenType $type,
        public ?string $token = null,
        public ?RefreshTokenRecord $refreshToken = null,
        public ?TokenVerificationResult $verification = null,
        public ?string $code = null,
        public array $context = [],
    ) {}

    public function failed(): bool
    {
        return !$this->successful();
    }

    public function successful(): bool
    {
        if ($this->verification !== null) {
            return $this->verification->verified;
        }

        return $this->token !== null;
    }
}
