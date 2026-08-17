<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtFailureReason;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\IssuedRefreshToken;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenClaims;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptRefreshTokenService implements RefreshTokenServiceInterface
{
    private const string TYPE = 'foundation-refresh+jwt';

    public function __construct(
        private EpicryptTokenFactory $factory,
    ) {}

    public function issue(RefreshTokenClaims $claims): IssuedRefreshToken
    {
        $token = $this->factory->jwtIssuer(self::TYPE)->issue(new JwtClaims(
            issuer: $this->factory->issuer(),
            subject: $claims->accountId,
            audiences: [$this->factory->audience()],
            expiresAt: $claims->expiresAt,
            notBefore: $claims->issuedAt,
            issuedAt: $claims->issuedAt,
            jwtId: $claims->tokenId,
            custom: [
                'aid' => $claims->accountId,
                'cid' => $claims->clientId,
                'did' => $claims->deviceId,
                'fam' => $claims->familyId,
                'metadata' => $claims->metadata,
                'pur' => 'refresh',
            ],
        ));

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
        $result = $this->factory->jwtVerifier(self::TYPE)->verifyResult($token);
        if (!$result->valid) {
            return new TokenVerificationResult(
                false,
                failureReason: $result->failureReason === JwtFailureReason::EXPIRED
                    ? 'expired_token'
                    : 'invalid_token',
            );
        }

        $claims = $result->claims;
        if (($claims['pur'] ?? null) !== 'refresh') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            tokenId: is_string($claims['jti'] ?? null) ? $claims['jti'] : null,
            claims: $claims,
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        );
    }
}
