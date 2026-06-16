<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleEmailVerificationTokenService implements EmailVerificationTokenServiceInterface
{
    public function __construct(
        private HmacTokenCodec $codec,
        private ClockInterface $clock,
        private int $ttlSeconds = 3600,
    ) {}

    public function issue(string $accountId, string $email, array $context = []): string
    {
        $issuedAt = $this->clock->now();

        return $this->codec->encode([
            'ctx' => $context,
            'email' => $email,
            'exp' => $issuedAt + $this->ttlSeconds,
            'iat' => $issuedAt,
            'pur' => 'email_verification',
            'sub' => $accountId,
            'tid' => bin2hex(random_bytes(16)),
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->codec->decode($token);
        if ($claims === null || ($claims['pur'] ?? null) !== 'email_verification') {
            return new TokenVerificationResult(false, failureReason: 'invalid_token');
        }

        $expiresAt = is_int($claims['exp'] ?? null) ? $claims['exp'] : null;
        if ($expiresAt !== null && $expiresAt <= $this->clock->now()) {
            return new TokenVerificationResult(false, failureReason: 'expired_token', expiresAt: $expiresAt);
        }

        $context = is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [];

        return new TokenVerificationResult(
            verified: true,
            subjectId: is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            tokenId: is_string($claims['tid'] ?? null) ? $claims['tid'] : null,
            claims: ['request_id' => $context['request_id'] ?? null, 'email' => $claims['email'] ?? null] + $context,
            expiresAt: $expiresAt,
        );
    }
}
