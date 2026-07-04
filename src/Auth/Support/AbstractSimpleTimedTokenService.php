<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

abstract readonly class AbstractSimpleTimedTokenService
{
    use NormalizesTokenClaims;

    public function __construct(
        protected HmacTokenCodec $codec,
        protected ClockInterface $clock,
        protected int $ttlSeconds,
    ) {}

    /**
     * @param array<string, mixed> $claims
     */
    protected function issueTimedToken(array $claims): string
    {
        $issuedAt = $this->clock->now();

        return $this->codec->encode(array_merge($claims, [
            'exp' => $issuedAt + $this->ttlSeconds,
            'iat' => $issuedAt,
            'tid' => bin2hex(random_bytes(16)),
        ]));
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
            expiresAt: $this->expiresAt($claims),
        );
    }

    /**
     * @return array<string, mixed>|TokenVerificationResult
     */
    protected function verifyTimedToken(string $token, string $purpose): array|TokenVerificationResult
    {
        $claims = $this->codec->decode($token);
        if ($claims === null || ($claims['pur'] ?? null) !== $purpose) {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $expiresAt = $this->expiresAt($claims);
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return new TokenVerificationResult(false, failureReason: 'expired_token', expiresAt: $expiresAt);
        }

        return $claims;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function expiresAt(array $claims): ?int
    {
        return is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
    }
}
