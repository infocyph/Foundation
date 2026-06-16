<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptAccessTokenService implements AccessTokenServiceInterface
{
    public function __construct(
        private EpicryptTokenFactory $factory,
    ) {}

    public function issue(AccessTokenClaims $claims): string
    {
        return $this->factory->jwt()->encode([
            'act' => $claims->actorId,
            'aud' => $this->factory->audience(),
            'exp' => $claims->expiresAt,
            'iss' => $this->factory->issuer(),
            'metadata' => $claims->metadata,
            'nbf' => $claims->issuedAt,
            'pur' => 'access',
            'scopes' => $claims->scopes,
            'sub' => $claims->subjectId,
        ], $this->factory->key());
    }

    public function verify(string $token): TokenVerificationResult
    {
        $result = $this->factory->jwt()->verifyResult($token, $this->factory->key());
        if (!$result->verified) {
            return new TokenVerificationResult(
                false,
                failureReason: $result->expired ? 'expired_token' : 'invalid_token',
            );
        }

        $claims = $result->claims;
        if (($claims['pur'] ?? null) !== 'access') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            claims: $claims,
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        );
    }
}
