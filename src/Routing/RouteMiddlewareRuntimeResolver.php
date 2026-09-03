<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Closure;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Middleware\OAuthAudienceMiddleware;
use Infocyph\Foundation\Http\Middleware\OAuthScopeMiddleware;
use Infocyph\Foundation\Http\Middleware\PermissionMiddleware;
use Infocyph\Foundation\Http\Middleware\PolicyMiddleware;
use Infocyph\Foundation\Http\Middleware\RoleMiddleware;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

/**
 * Build-artifact-safe parameterized middleware resolvers.
 *
 * Webrick persists only these static resolver descriptors plus scalar route
 * parameters. The returned closure is created inside the active request scope,
 * where InterMix injects the Foundation services needed by the middleware.
 */
final class RouteMiddlewareRuntimeResolver
{
    private function __construct() {}

    public static function role(string ...$roles): Closure
    {
        $roles = array_values($roles);

        return static function (
            Request $request,
            Closure $next,
            CurrentPrincipalContext $principals,
            RoleManager $roleManager,
            AuthResponseFactory $responses,
        ) use ($roles): Response {
            return (new RoleMiddleware($principals, $roleManager, $responses, $roles))($request, $next);
        };
    }

    public static function permission(string ...$abilities): Closure
    {
        $abilities = array_values($abilities);

        return static function (
            Request $request,
            Closure $next,
            CurrentPrincipalContext $principals,
            AuthorizerInterface $authorizer,
            AuthExceptionMapper $exceptions,
            AuthResponseFactory $responses,
        ) use ($abilities): Response {
            return (new PermissionMiddleware(
                $principals,
                $authorizer,
                $exceptions,
                $responses,
                $abilities,
            ))($request, $next);
        };
    }

    public static function policy(string $ability, ?string $resourceKey = null): Closure
    {
        return static function (
            Request $request,
            Closure $next,
            CurrentPrincipalContext $principals,
            AuthorizerInterface $authorizer,
            AuthExceptionMapper $exceptions,
            AuthResponseFactory $responses,
        ) use ($ability, $resourceKey): Response {
            return (new PolicyMiddleware(
                $principals,
                $authorizer,
                $exceptions,
                $responses,
                $ability,
                $resourceKey,
            ))($request, $next);
        };
    }

    public static function oauthScope(string ...$scopes): Closure
    {
        $scopes = array_values($scopes);

        return static function (
            Request $request,
            Closure $next,
            CurrentPrincipalContext $principals,
        ) use ($scopes): Response {
            return (new OAuthScopeMiddleware($principals, $scopes))($request, $next);
        };
    }

    public static function oauthAudience(string ...$audiences): Closure
    {
        $audiences = array_values($audiences);

        return static function (
            Request $request,
            Closure $next,
            CurrentPrincipalContext $principals,
        ) use ($audiences): Response {
            return (new OAuthAudienceMiddleware($principals, $audiences))($request, $next);
        };
    }

    public static function oauthThrottle(string $endpoint): Closure
    {
        return static function (
            Request $request,
            Closure $next,
            OAuthHttpThrottleFactory $factory,
            CacheManager $cache,
            ConfigRepository $config,
        ) use ($endpoint): Response {
            $configured = $config->get('auth.oauth.rate_limit_store');
            $store = is_string($configured) && $configured !== '' ? $configured : null;

            return ($factory->forEndpoint($endpoint, $cache->store($store)))($request, $next);
        };
    }
}
