<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Epicrypt\Token\Payload\PurposeTokenFailureReason;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\IssuedRefreshToken;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenClaims;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleRefreshTokenService implements RefreshTokenServiceInterface
{
    private const string PURPOSE = 'refresh';

    public function __construct(
        private EpicryptPurposeTokenFactory $tokens,
        private ClockInterface $clock,
    ) {}

    public function issue(RefreshTokenClaims $claims): IssuedRefreshToken
    {
        $ttlSeconds = $claims->expiresAt - $this->clock->now();
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Refresh token expiration must be in the future.');
        }

        $purposeToken = $this->tokens->forPurpose(self::PURPOSE, $ttlSeconds);
        $token = $purposeToken->issue([
            'cid' => $claims->clientId,
            'did' => $claims->deviceId,
            'fam' => $claims->familyId,
            'metadata' => $claims->metadata,
        ], $claims->accountId);
        $issued = $purposeToken->verify($token);

        if (!$issued->verified || $issued->tokenId === null || $issued->expiresAt === null) {
            throw new \LogicException('Epicrypt failed to verify a newly issued refresh token.');
        }

        return new IssuedRefreshToken(
            value: $token,
            tokenHash: hash('sha256', $token),
            tokenId: $issued->tokenId,
            familyId: $claims->familyId,
            expiresAt: $issued->expiresAt,
        );
    }

    public function verify(string $token): TokenVerificationResult
    {
        $verification = $this->tokens
            ->forPurpose(self::PURPOSE, 3600)
            ->verify($token);
        if (!$verification->verified) {
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

        $claims = [
            'aid' => $verification->subjectId,
            'cid' => $verification->claims['cid'] ?? null,
            'did' => $verification->claims['did'] ?? null,
            'exp' => $verification->expiresAt,
            'fam' => $verification->claims['fam'] ?? null,
            'iat' => $verification->issuedAt,
            'metadata' => is_array($verification->claims['metadata'] ?? null)
                ? $verification->claims['metadata']
                : [],
            'pur' => self::PURPOSE,
            'tid' => $verification->tokenId,
        ];

        return new TokenVerificationResult(
            verified: true,
            subjectId: $verification->subjectId,
            tokenId: $verification->tokenId,
            claims: $claims,
            expiresAt: $verification->expiresAt,
        );
    }
}
