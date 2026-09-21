<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Auth\Oidc\OpenIdAuthorizationRequest;
use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;

final readonly class AuthorizationRequest
{
    /**
     * @param list<string> $scopes
     * @param list<string> $audiences
     * @param list<string> $requiredPermissions
     * @param list<string> $openIdPrompts
     * @param list<string> $openIdAcrValues
     */
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public string $codeChallenge,
        public array $scopes,
        public array $audiences,
        public array $requiredPermissions = [],
        public ?string $state = null,
        public ?string $openIdNonce = null,
        public array $openIdPrompts = [],
        public ?int $openIdMaximumAuthenticationAge = null,
        public array $openIdAcrValues = [],
        public ?OpenIdAuthorizationRequest $openIdProtocol = null,
    ) {}

    public function openId(): bool
    {
        return $this->openIdProtocol instanceof OpenIdAuthorizationRequest
            || in_array('openid', $this->scopes, true);
    }
}
