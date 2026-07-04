<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Authentication\PasswordReset\PasswordResetTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptPasswordResetTokenService extends AbstractEpicryptTimedTokenService implements PasswordResetTokenServiceInterface
{
    private const string CONTEXT = 'auth.password_reset';

    public function issue(string $accountId, array $context = []): string
    {
        return $this->issueTimedToken(self::CONTEXT, [
            'ctx' => $context,
            'pur' => 'password_reset',
            'sub' => $accountId,
            'tid' => bin2hex(random_bytes(16)),
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, self::CONTEXT, 'password_reset');
        if ($claims instanceof TokenVerificationResult) {
            return $claims;
        }

        $context = is_array($claims['ctx'] ?? null) ? $claims['ctx'] : [];

        return $this->verifiedResult(
            $claims,
            is_string($claims['sub'] ?? null) ? $claims['sub'] : null,
            $this->normalizeClaims(['request_id' => $context['request_id'] ?? null] + $context),
        );
    }
}
