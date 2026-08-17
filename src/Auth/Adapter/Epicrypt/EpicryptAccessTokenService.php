<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Epicrypt\Token\Jwt\JwtClaims;
use Infocyph\Epicrypt\Token\Jwt\JwtFailureReason;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptAccessTokenService implements AccessTokenServiceInterface
{
    private const string TYPE = 'at+jwt';

    public function __construct(
        private EpicryptTokenFactory $factory,
    ) {}

    public function issue(AccessTokenClaims $claims): string
    {
        return $this->factory->jwtIssuer(self::TYPE)->issue(new JwtClaims(
            issuer: $this->factory->issuer(),
            subject: $claims->subjectId,
            audiences: [$this->factory->audience()],
            expiresAt: $claims->expiresAt,
            notBefore: $claims->issuedAt,
            issuedAt: $claims->issuedAt,
            jwtId: bin2hex(random_bytes(24)),
            custom: [
                'act' => $claims->actorId,
                'metadata' => $claims->metadata,
                'pur' => 'access',
                'scopes' => $claims->scopes,
            ],
        ));
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
        if (($claims['pur'] ?? null) !== 'access') {
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
