<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt;

use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class EpicryptPasswordlessTokenService extends AbstractEpicryptTimedTokenService implements PasswordlessTokenServiceInterface
{
    private const string CONTEXT = 'auth.passwordless';

    public function issue(string $identifier, array $context = []): string
    {
        return $this->issueTimedToken(self::CONTEXT, [
            'ctx' => $context,
            'identifier' => $identifier,
            'pur' => 'passwordless',
            'tid' => bin2hex(random_bytes(16)),
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, self::CONTEXT, 'passwordless');
        if ($claims instanceof TokenVerificationResult) {
            return $claims;
        }

        return $this->verifiedResult(
            $claims,
            is_string($claims['identifier'] ?? null) ? $claims['identifier'] : null,
            $this->normalizeClaims(is_array($claims['ctx'] ?? null) ? $claims['ctx'] : []),
        );
    }
}
