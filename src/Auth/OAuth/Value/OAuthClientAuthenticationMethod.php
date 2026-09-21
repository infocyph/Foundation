<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Value;

enum OAuthClientAuthenticationMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';

    case ClientSecretPost = 'client_secret_post';

    case None = 'none';

    case PrivateKeyJwt = 'private_key_jwt';
}
