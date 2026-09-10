<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Epicrypt\Token\Payload\PurposeTokenFailureReason;
use Infocyph\Epicrypt\Token\Payload\PurposeTokenVerificationResult;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

abstract readonly class AbstractSimpleTimedTokenService
{
    use NormalizesTokenClaims;

    public function __construct(
        protected EpicryptPurposeTokenFactory $tokens,
        protected int $ttlSeconds,
    ) {}

    /**
     * @param array<string, mixed> $claims
     */
    protected function issueTimedToken(string $purpose, array $claims, ?string $subjectId = null): string
    {
        return $this->tokens
            ->forPurpose($purpose, $this->ttlSeconds)
            ->issue($claims, $subjectId);
    }

    /**
     * @param array<string, mixed> $normalizedClaims
     */
    protected function verifiedResult(
        PurposeTokenVerificationResult $verification,
        ?string $subjectId,
        array $normalizedClaims,
    ): TokenVerificationResult {
        return new TokenVerificationResult(
            verified: true,
            subjectId: $subjectId,
            tokenId: $verification->tokenId,
            claims: $normalizedClaims,
            expiresAt: $verification->expiresAt,
        );
    }

    protected function verifyTimedToken(string $token, string $purpose): PurposeTokenVerificationResult|TokenVerificationResult
    {
        $verification = $this->tokens
            ->forPurpose($purpose, $this->ttlSeconds)
            ->verify($token);
        if ($verification->verified) {
            return $verification;
        }

        return new TokenVerificationResult(
            verified: false,
            subjectId: $verification->subjectId,
            tokenId: $verification->tokenId,
            expiresAt: $verification->expiresAt,
            failureReason: $verification->failureReason === PurposeTokenFailureReason::EXPIRED_TOKEN
                ? 'expired_token'
                : 'invalid_token',
        );
    }
}
