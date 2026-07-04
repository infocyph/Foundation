<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Security\SecurityManager;

final class Security extends Facade
{
    public static function accessTokens(): AccessTokenServiceInterface
    {
        return self::manager()->accessTokens();
    }

    public static function manager(): SecurityManager
    {
        return self::app()->security();
    }

    public static function passwordHasher(): PasswordHasherInterface
    {
        return self::manager()->passwordHasher();
    }

    public static function passwordVerifier(): PasswordVerifierInterface
    {
        return self::manager()->passwordVerifier();
    }

    public static function refreshTokens(): RefreshTokenServiceInterface
    {
        return self::manager()->refreshTokens();
    }
}
