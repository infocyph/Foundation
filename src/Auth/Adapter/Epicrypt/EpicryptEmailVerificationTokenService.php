<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptEmailVerificationTokenService extends AbstractEpicryptTimedTokenService implements EmailVerificationTokenServiceInterface
{
    private const string CONTEXT = 'auth.email_verification';

    public function issue(string $accountId, string $email, array $context = []): string
    {
        return $this->issueTimedToken(self::CONTEXT, [
            'ctx' => $context,
            'email' => $email,
            'pur' => 'email_verification',
            'sub' => $accountId,
            'tid' => bin2hex(random_bytes(16)),
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, self::CONTEXT, 'email_verification');
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
