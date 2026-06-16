<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Epicrypt\Password\PasswordHasher;

final readonly class EpicryptPasswordVerifier implements PasswordVerifierInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        private PasswordHasher $hasher,
        private array $options = [],
    ) {}

    public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
    {
        $result = $this->hasher->verifyAndRehash($plainPassword, $storedHash, $this->options);

        return new PasswordVerificationResult(
            verified: $result->verified,
            needsRehash: $result->needsRehash,
            rehash: $result->rehashedHash,
        );
    }
}
