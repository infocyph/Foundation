<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Epicrypt;

use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Security\TokenVerificationResult;

final readonly class EpicryptPasswordlessTokenService implements PasswordlessTokenServiceInterface
{
    private const string CONTEXT = 'auth.passwordless';

    public function __construct(
        private EpicryptTokenFactory $factory,
        private int $ttlSeconds = 900,
    ) {}

    public function issue(string $identifier, array $context = []): string
    {
        return $this->factory->payload(self::CONTEXT)->encode([
            'ctx' => $context,
            'identifier' => $identifier,
            'pur' => 'passwordless',
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
        if (($claims['pur'] ?? null) !== 'passwordless') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['identifier'] ?? null) ? $claims['identifier'] : null,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [],
            expiresAt: is_int($claims['exp'] ?? null) ? $claims['exp'] : null,
        );
    }
}
