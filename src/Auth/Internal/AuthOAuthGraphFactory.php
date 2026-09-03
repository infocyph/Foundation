<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthSigningKeySet;

final class AuthOAuthGraphFactory
{
    public static function signingKeySet(OAuthSigningKeyResolver $resolver): OAuthSigningKeySet
    {
        return $resolver->resolve();
    }
}
