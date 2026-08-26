<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Value;

enum OAuthClientType: string
{
    case Confidential = 'confidential';

    case Public = 'public';
}
