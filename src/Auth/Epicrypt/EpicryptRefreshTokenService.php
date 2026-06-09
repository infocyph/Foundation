<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Epicrypt;

use Infocyph\AuthLayer\Authentication\TokenAuth\IssuedRefreshToken;
use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenClaims;
use Infocyph\AuthLayer\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Security\TokenVerificationResult;

final readonly class EpicryptRefreshTokenService implements RefreshTokenServiceInterface
{
    public function __construct(
        private EpicryptTokenFactory $factory,
    ) {}

    public function issue(RefreshTokenClaims $claims): IssuedRefreshToken
    {
        $token = $this->factory->jwt(true)->encode([
            'aid' => $claims->accountId,
            'aud' => $this->factory->audience(),
            'cid' => $claims->clientId,
            'did' => $claims->deviceId,
            'exp' => $claims->expiresAt,
            'fam' => $claims->familyId,
            'iss' => $this->factory->issuer(),
            'jti' => $claims->tokenId,
            'metadata' => $claims->metadata,
            'nbf' => $claims->issuedAt,
            'pur' => 'refresh',
            'sub' => $claims->accountId,
        ], $this->factory->key());

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
        $result = $this->factory->jwt(true)->verifyResult($token, $this->factory->key());
        if (!$result->verified) {
            return new TokenVerificationResult(
                false,
                failureReason: $result->expired ? 'expired_token' : 'invalid_token',
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
