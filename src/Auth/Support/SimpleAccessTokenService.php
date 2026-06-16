<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleAccessTokenService implements AccessTokenServiceInterface
{
    public function __construct(
        private HmacTokenCodec $codec,
        private ClockInterface $clock,
    ) {}

    public function issue(AccessTokenClaims $claims): string
    {
        return $this->codec->encode([
            'act' => $claims->actorId,
            'exp' => $claims->expiresAt,
            'iat' => $claims->issuedAt,
            'metadata' => $claims->metadata,
            'pur' => 'access',
            'scopes' => $claims->scopes,
            'sub' => $claims->subjectId,
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->codec->decode($token);

        if ($claims === null || ($claims['pur'] ?? null) !== 'access') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $expiresAt = is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return new TokenVerificationResult(false, failureReason: 'expired_token', expiresAt: $expiresAt);
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            claims: $claims,
            expiresAt: $expiresAt,
        );
    }
}
