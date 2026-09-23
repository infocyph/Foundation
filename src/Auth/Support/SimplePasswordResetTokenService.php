<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimplePasswordResetTokenService extends AbstractSimpleTimedTokenService implements PasswordResetTokenServiceInterface
{
    private const string PURPOSE = 'password_reset';

    public function issue(string $accountId, array $context = []): string
    {
        return $this->issueTimedToken(
            self::PURPOSE,
            ['ctx' => $context],
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
            $this->normalizeClaims(
                ['request_id' => $context['request_id'] ?? null] + $context,
            ),
        );
    }
}
