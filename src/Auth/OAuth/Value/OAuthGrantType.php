<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Value;

enum OAuthGrantType: string
{
    case AuthorizationCode = 'authorization_code';
    case ClientCredentials = 'client_credentials';
    case RefreshToken = 'refresh_token';
}
