<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Value;

enum OAuthClientAuthenticationMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case None = 'none';
}
