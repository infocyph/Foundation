<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimplePasswordResetTokenService extends AbstractSimpleTimedTokenService implements PasswordResetTokenServiceInterface
{
    public function issue(string $accountId, array $context = []): string
    {
        return $this->issueTimedToken([
            'ctx' => $context,
            'pur' => 'password_reset',
            'sub' => $accountId,
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, 'password_reset');
        if ($claims instanceof TokenVerificationResult) {
            return $claims;
        }

        return $this->verifiedResult(
            $claims,
            is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            $this->normalizeClaims(
                is_array($claims['ctx'] ?? null)
                    ? ['request_id' => $claims['ctx']['request_id'] ?? null] + $claims['ctx']
                    : [],
            ),
        );
    }
}
