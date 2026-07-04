<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Auth\Authentication\Login\LoginResult;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Http\LogoutResult;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;

final class Auth extends Facade
{
    public static function actions(): AuthActions
    {
        return self::app()->authActions();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function attempt(array $payload): LoginResult
    {
        return self::actions()->login($payload);
    }

    public static function logout(?string $sessionId = null): LogoutResult
    {
        return self::actions()->logout($sessionId);
    }

    public static function manager(): AuthManager
    {
        return self::app()->authManager();
    }

    public static function principal(): CurrentPrincipalContext
    {
        return self::manager()->principal();
    }

    /**
     * @return array<string, mixed>
     */
    public static function readinessReport(): array
    {
        return self::manager()->readinessReport();
    }

    public static function services(): AuthServices
    {
        return self::app()->auth();
    }
}
