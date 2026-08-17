<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;

final readonly class EpicryptPasswordVerifier implements PasswordVerifierInterface
{
    public function __construct(
        private PasswordHasher $hasher,
    ) {}

    public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
    {
        $result = $this->hasher->verifyAndRehash($plainPassword, $storedHash);

        return new PasswordVerificationResult(
            verified: $result->verified,
            needsRehash: $result->needsRehash,
            rehash: $result->rehashedHash,
        );
    }
}
