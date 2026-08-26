<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Authorization;

enum OAuthAuthorizationCodeConsumeStatus: string
{
    case Consumed = 'consumed';
    case Expired = 'expired';
    case Missing = 'missing';
    case Mismatched = 'mismatched';
    case Reused = 'reused';
}
