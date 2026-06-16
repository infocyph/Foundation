<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptPasswordResetTokenService implements PasswordResetTokenServiceInterface
{
    private const string CONTEXT = 'auth.password_reset';

    public function __construct(
        private EpicryptTokenFactory $factory,
        private int $ttlSeconds = 3600,
    ) {}

    public function issue(string $accountId, array $context = []): string
    {
        return $this->factory->payload(self::CONTEXT)->encode([
            'ctx' => $context,
            'pur' => 'password_reset',
            'sub' => $accountId,
            'tid' => bin2hex(random_bytes(16)),
        ], $this->factory->key(), [
            'exp' => $this->factory->now() + $this->ttlSeconds,
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $result = $this->factory->payload(self::CONTEXT)->verifyResult($token, $this->factory->key());
        if (!$result->verified) {
            return new TokenVerificationResult(
                false,
                failureReason: $result->expired ? 'expired_token' : 'invalid_token',
            );
        }

        $claims = $result->claims;
        if (($claims['pur'] ?? null) !== 'password_reset') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $context = is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [];

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: ['request_id' => $context['request_id'] ?? null] + $context,
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        );
    }
}
