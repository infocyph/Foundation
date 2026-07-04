<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleEmailVerificationTokenService extends AbstractSimpleTimedTokenService implements EmailVerificationTokenServiceInterface
{
    public function issue(string $accountId, string $email, array $context = []): string
    {
        return $this->issueTimedToken([
            'ctx' => $context,
            'email' => $email,
            'pur' => 'email_verification',
            'sub' => $accountId,
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, 'email_verification');
        if ($claims instanceof TokenVerificationResult) {
            return $claims;
        }

        $context = is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [];

        return $this->verifiedResult(
            $claims,
            is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            $this->normalizeClaims([
                'request_id' => $context['request_id'] ?? null,
                'email' => $claims['email'] ?? null,
            ] + $context),
        );
    }
}
