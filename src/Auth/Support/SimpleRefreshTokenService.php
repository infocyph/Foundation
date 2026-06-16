<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\IssuedRefreshToken;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenClaims;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleRefreshTokenService implements RefreshTokenServiceInterface
{
    public function __construct(
        private HmacTokenCodec $codec,
        private ClockInterface $clock,
    ) {}

    public function issue(RefreshTokenClaims $claims): IssuedRefreshToken
    {
        $token = $this->codec->encode([
            'aid' => $claims->accountId,
            'cid' => $claims->clientId,
            'did' => $claims->deviceId,
            'exp' => $claims->expiresAt,
            'fam' => $claims->familyId,
            'iat' => $claims->issuedAt,
            'metadata' => $claims->metadata,
            'pur' => 'refresh',
            'tid' => $claims->tokenId,
        ]);

        return new IssuedRefreshToken(
            value: $token,
            tokenHash: hash('sha256', $token),
            tokenId: $claims->tokenId,
            familyId: $claims->familyId,
            expiresAt: $claims->expiresAt,
        );
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->codec->decode($token);

        if ($claims === null || ($claims['pur'] ?? null) !== 'refresh') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $expiresAt = is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return new TokenVerificationResult(
                verified: false,
                subjectId: is_string($claims['aid'] ?? null) ? $claims['aid'] : null,
                tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
                claims: $claims,
                expiresAt: $expiresAt,
                failureReason: 'expired_token',
            );
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['aid'] ?? null) ? $claims['aid'] : null,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: $claims,
            expiresAt: $expiresAt,
        );
    }
}
