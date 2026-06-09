<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\AuthLayer\Mfa\MfaChallenge;
use Infocyph\AuthLayer\Mfa\MfaVerificationResult;
use Infocyph\AuthLayer\Mfa\MfaVerifierInterface;

final readonly class SimpleMfaVerifier implements MfaVerifierInterface
{
    public function __construct(
        private string $defaultCode = '000000',
    ) {}

    public function verify(MfaChallenge $challenge, string $code): MfaVerificationResult
    {
        $expected = $challenge->metadata['code'] ?? $this->defaultCode;

        return new MfaVerificationResult(
            verified: is_string($expected) && hash_equals($expected, $code),
            factorId: $challenge->factorId,
            reason: $code === $expected ? null : 'invalid_mfa_code',
        );
    }
}
