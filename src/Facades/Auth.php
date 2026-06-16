<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\LogoutResult;

final class Auth extends Facade
{
    public static function actions(): \Infocyph\Foundation\Auth\Http\AuthActions
    {
        return static::app()->authActions();
    }

    public static function attempt(array $payload): LoginResult
    {
        return static::actions()->login($payload);
    }

    public static function logout(?string $sessionId = null): LogoutResult
    {
        return static::actions()->logout($sessionId);
    }

    public static function manager(): AuthManager
    {
        return static::app()->authManager();
    }

    public static function principal(): \Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext
    {
        return static::manager()->principal();
    }

    public static function readinessReport(): array
    {
        return static::manager()->readinessReport();
    }

    public static function services(): AuthServices
    {
        return static::app()->auth();
    }
}
