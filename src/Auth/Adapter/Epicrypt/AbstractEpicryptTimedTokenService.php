<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;
use Infocyph\Foundation\Auth\Support\NormalizesTokenClaims;

abstract readonly class AbstractEpicryptTimedTokenService
{
    use NormalizesTokenClaims;

    public function __construct(
        protected EpicryptTokenFactory $factory,
        protected int $ttlSeconds,
    ) {}

    /**
     * @param array<string, mixed> $claims
     */
    protected function issueTimedToken(string $context, array $claims): string
    {
        return $this->factory->payload($context)->encode(
            $claims,
            $this->factory->key(),
            ['exp' => $this->factory->now() + $this->ttlSeconds],
        );
    }

    /**
     * @param array<string, mixed> $claims
     * @param array<string, mixed> $normalizedClaims
     */
    protected function verifiedResult(array $claims, ?string $subjectId, array $normalizedClaims): TokenVerificationResult
    {
        return new TokenVerificationResult(
            verified: true,
            subjectId: $subjectId,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: $normalizedClaims,
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        );
    }

    /**
     * @return array<string, mixed>|TokenVerificationResult
     */
    protected function verifyTimedToken(string $token, string $context, string $purpose): array|TokenVerificationResult
    {
        $result = $this->factory->payload($context)->verifyResult($token, $this->factory->key());
        if (!$result->verified) {
            return new TokenVerificationResult(
                false,
                failureReason: $result->expired ? 'expired_token' : 'invalid_token',
            );
        }

        $claims = $result->claims;
        if (($claims['pur'] ?? null) !== $purpose) {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        return $claims;
    }
}
