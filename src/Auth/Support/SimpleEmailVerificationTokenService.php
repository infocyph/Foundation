<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\EmailVerification\EmailVerificationTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimpleEmailVerificationTokenService extends AbstractSimpleTimedTokenService implements EmailVerificationTokenServiceInterface
{
    private const string PURPOSE = 'email_verification';

    public function issue(string $accountId, string $email, array $context = []): string
    {
        return $this->issueTimedToken(
            self::PURPOSE,
            [
                'ctx' => $context,
                'email' => $email,
            ],
            $accountId,
        );
    }

    public function verify(string $token): TokenVerificationResult
    {
        $verification = $this->verifyTimedToken($token, self::PURPOSE);
        if ($verification instanceof TokenVerificationResult) {
            return $verification;
        }

        $context = is_array($verification->claims['ctx'] ?? null)
            ? $verification->claims['ctx']
            : [];

        return $this->verifiedResult(
            $verification,
            $verification->subjectId,
            $this->normalizeClaims([
                'request_id' => $context['request_id'] ?? null,
                'email' => $verification->claims['email'] ?? null,
            ] + $context),
        );
    }
}
