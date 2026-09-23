<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Adapter\Epicrypt\Oidc;

use Infocyph\Epicrypt\Auth\Oidc\OpenIdSubjectIdentifierProviderInterface;

final readonly class FoundationOpenIdSubjectProvider implements OpenIdSubjectIdentifierProviderInterface
{
    public function subject(string $principalId, string $clientId): string
    {
        unset($clientId);

        return $principalId;
    }
}
