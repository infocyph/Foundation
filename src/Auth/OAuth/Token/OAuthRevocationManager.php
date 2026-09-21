<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

use Infocyph\Epicrypt\Auth\OAuth\OAuthRevocationEndpoint;
use Infocyph\Epicrypt\Auth\OAuth\OAuthTokenTypeHint;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthClientAuthenticationAdapter;
use Infocyph\Foundation\Auth\Adapter\Epicrypt\OAuth\EpicryptOAuthErrorMapper;

final readonly class OAuthRevocationManager
{
    public function __construct(
        private OAuthRevocationEndpoint $endpoint,
        private EpicryptOAuthClientAuthenticationAdapter $authentication,
    ) {}

    public function revoke(
        #[\SensitiveParameter]
        string $token,
        OAuthClientAuthentication $authentication,
        ?string $tokenTypeHint = null,
    ): void {
        $hint = match ($tokenTypeHint) {
            'access_token' => OAuthTokenTypeHint::ACCESS_TOKEN,
            'refresh_token' => OAuthTokenTypeHint::REFRESH_TOKEN,
            default => null,
        };
        $result = $this->endpoint->revoke(
            clientId: $authentication->clientId,
            authentication: $this->authentication->authenticate($authentication),
            token: $token,
            hint: $hint,
        );
        if (!$result->accepted) {
            throw EpicryptOAuthErrorMapper::exception(
                $result->error === null ? null : new \Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError($result->error),
            );
        }
    }
}
