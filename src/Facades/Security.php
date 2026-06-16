<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Authentication\TokenAuth\RefreshTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;

final class Security extends Facade
{
    public static function accessTokens(): AccessTokenServiceInterface
    {
        return static::manager()->accessTokens();
    }

    public static function manager(): \Infocyph\Foundation\Security\SecurityManager
    {
        return static::app()->security();
    }

    public static function passwordHasher(): PasswordHasherInterface
    {
        return static::manager()->passwordHasher();
    }

    public static function passwordVerifier(): PasswordVerifierInterface
    {
        return static::manager()->passwordVerifier();
    }

    public static function refreshTokens(): RefreshTokenServiceInterface
    {
        return static::manager()->refreshTokens();
    }
}
