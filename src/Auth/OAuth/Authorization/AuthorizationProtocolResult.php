<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Epicrypt\Auth\OAuth\OAuthAuthorizationRequest;
use Infocyph\Epicrypt\Auth\OAuth\OAuthProtocolError;
use Infocyph\Epicrypt\Auth\Oidc\OpenIdAuthorizationRequest;

final readonly class AuthorizationProtocolResult
{
    public function __construct(
        public ?OAuthAuthorizationRequest $request,
        public ?OpenIdAuthorizationRequest $openId,
        public ?OAuthProtocolError $error,
    ) {}
}
