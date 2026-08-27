<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

enum OAuthAuthorizationCodeConsumeStatus: string
{
    case Consumed = 'consumed';

    case Expired = 'expired';

    case Mismatched = 'mismatched';

    case Missing = 'missing';

    case Reused = 'reused';
}
