<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\OAuth\Token;

/** @deprecated Compatibility enum for the legacy Foundation OAuth refresh flow. */
enum OAuthRefreshRotationStatus: string
{
    case Missing = 'missing';

    case Reused = 'reused';

    case Revoked = 'revoked';

    case Rotated = 'rotated';
}
