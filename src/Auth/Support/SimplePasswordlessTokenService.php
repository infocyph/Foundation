<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimplePasswordlessTokenService extends AbstractSimpleTimedTokenService implements PasswordlessTokenServiceInterface
{
    public function issue(string $identifier, array $context = []): string
    {
        return $this->issueTimedToken([
            'ctx' => $context,
            'identifier' => $identifier,
            'pur' => 'passwordless',
        ]);
    }

    public function verify(string $token): TokenVerificationResult
    {
        $claims = $this->verifyTimedToken($token, 'passwordless');
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
