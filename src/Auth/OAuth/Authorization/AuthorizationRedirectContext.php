<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

use Infocyph\Foundation\Auth\OAuth\Client\OAuthClient;

final readonly class AuthorizationRedirectContext
{
    public function __construct(
        public OAuthClient $client,
        public string $redirectUri,
        public ?string $state = null,
    ) {}
}
