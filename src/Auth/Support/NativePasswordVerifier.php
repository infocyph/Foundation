<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerificationResult;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;

final readonly class NativePasswordVerifier implements PasswordVerifierInterface
{
    public function __construct(
        private PasswordHasherInterface $hasher,
    ) {}

    public function verify(string $plainPassword, string $storedHash): PasswordVerificationResult
    {
        $verified = password_verify($plainPassword, $storedHash);
        if (!$verified) {
            return new PasswordVerificationResult(false);
        }

        $needsRehash = password_needs_rehash($storedHash, PASSWORD_DEFAULT);

        return new PasswordVerificationResult(
            verified: true,
            needsRehash: $needsRehash,
            rehash: $needsRehash ? $this->hasher->hash($plainPassword) : null,
        );
    }
}
