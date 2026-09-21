<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc;

use Infocyph\Epicrypt\Auth\Oidc\OpenIdClaimsProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;

final readonly class FoundationOpenIdClaimsProvider implements OpenIdClaimsProviderInterface
{
    private const array PROFILE_CLAIMS = [
        'name',
        'given_name',
        'family_name',
        'preferred_username',
    ];

    public function __construct(private AccountProviderInterface $accounts) {}

    public function claims(string $principalId, string $clientId, array $scopes): array
    {
        unset($clientId);

        $account = $this->accounts->findById($principalId);
        if ($account === null) {
            return [];
        }

        $metadata = $account->metadata();
        $claims = [];
        if (in_array('profile', $scopes, true)) {
            foreach (self::PROFILE_CLAIMS as $name) {
                $value = $metadata[$name] ?? null;
                if (is_string($value) && $value !== '') {
                    $claims[$name] = $value;
                }
            }
        }

        if (in_array('email', $scopes, true)) {
            $email = $metadata['email'] ?? $account->identifier();
            if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $claims['email'] = $email;
                $verified = $metadata['email_verified'] ?? null;
                if (is_bool($verified)) {
                    $claims['email_verified'] = $verified;
                }
            }
        }

        return $claims;
    }
}
