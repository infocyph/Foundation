<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\AuthLayer\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\AuthLayer\Contract\Clock\ClockInterface;
use Infocyph\AuthLayer\Contract\Security\TokenVerificationResult;

final readonly class SimplePasswordlessTokenService implements PasswordlessTokenServiceInterface
{
    public function __construct(
        private HmacTokenCodec $codec,
        private ClockInterface $clock,
        private int $ttlSeconds = 900,
    ) {}

    public function issue(string $identifier, array $context = []): string
    {
        $issuedAt = $this->clock->now();

        return $this->codec->encode([
            'ctx' => $context,
            'exp' => $issuedAt + $this->ttlSeconds,
            'iat' => $issuedAt,
            'identifier' => $identifier,
            'pur' => 'passwordless',
            'tid' => bin2hex(random_bytes(16)),
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->codec->decode($token);
        if ($claims === null || ($claims['pur'] ?? null) !== 'passwordless') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $expiresAt = is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return new TokenVerificationResult(false, failureReason: 'expired_token', expiresAt: $expiresAt);
        }

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['identifier'] ?? null) ? $claims['identifier'] : null,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [],
            expiresAt: $expiresAt,
        );
    }
}
