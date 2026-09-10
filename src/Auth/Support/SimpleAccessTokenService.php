<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Epicrypt\Token\Payload\PurposeTokenFailureReason;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\EpicryptPurposeTokenFactory;
use Infocyph\Foundation\Auth\Authentication\TokenAuth\AccessTokenClaims;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleAccessTokenService implements AccessTokenServiceInterface
{
    private const string PURPOSE = 'access';

    public function __construct(
        private EpicryptPurposeTokenFactory $tokens,
        private ClockInterface $clock,
    ) {}

    public function issue(AccessTokenClaims $claims): string
    {
        $ttlSeconds = $claims->expiresAt - $this->clock->now();
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Access token expiration must be in the future.');
        }

        return $this->tokens
            ->forPurpose(self::PURPOSE, $ttlSeconds)
            ->issue([
                'act' => $claims->actorId,
                'metadata' => $claims->metadata,
                'scopes' => $claims->scopes,
            ], $claims->subjectId);
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
            'act' => $verification->claims['act'] ?? null,
            'exp' => $verification->expiresAt,
            'iat' => $verification->issuedAt,
            'metadata' => is_array($verification->claims['metadata'] ?? null)
                ? $verification->claims['metadata']
                : [],
            'pur' => self::PURPOSE,
            'scopes' => is_array($verification->claims['scopes'] ?? null)
                ? $verification->claims['scopes']
                : [],
            'sub' => $verification->subjectId,
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
