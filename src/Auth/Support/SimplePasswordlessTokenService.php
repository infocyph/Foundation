<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Support;

use Infocyph\Foundation\Auth\Authentication\Passwordless\PasswordlessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\TokenVerificationResult;

final readonly class SimplePasswordlessTokenService extends AbstractSimpleTimedTokenService implements PasswordlessTokenServiceInterface
{
    private const string PURPOSE = 'passwordless';

    public function issue(string $identifier, array $context = []): string
    {
        return $this->issueTimedToken(
            self::PURPOSE,
            [
                'ctx' => $context,
                'identifier' => $identifier,
            ],
        );
    }

    public function verify(string $token): TokenVerificationResult
    {
        $verification = $this->verifyTimedToken($token, self::PURPOSE);
        if ($verification instanceof TokenVerificationResult) {
            return $verification;
        }

        return $this->verifiedResult(
            $verification,
            is_string($verification->claims['identifier'] ?? null)
                ? $verification->claims['identifier']
                : null,
            $this->normalizeClaims(
                is_array($verification->claims['ctx'] ?? null)
                    ? $verification->claims['ctx']
                    : [],
            ),
        );
    }
}
