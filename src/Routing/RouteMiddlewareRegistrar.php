<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\PermissionMiddleware;
use Infocyph\Foundation\Http\Middleware\PolicyMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Middleware\RoleMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\Middleware\SessionMiddleware;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final class RouteMiddlewareRegistrar
{
    /** @var array<string, true> */
    private const array AUTH_ALIASES = [
        'resolve-auth' => true,
        'auth' => true,
        'guest' => true,
        'verified' => true,
        'mfa' => true,
        'recent' => true,
        'role' => true,
        'permission' => true,
        'policy' => true,
        'oauth-throttle' => true,
    ];

    /** @var array<string, true> */
    private const array SESSION_ALIASES = [
        'session' => true,
        'csrf' => true,
    ];

    private bool $fullyRegistered = false;

    public function __construct(
        private readonly Application $app,
    ) {}

    /**
     * Register only middleware families referenced by a warm route cache.
     *
     * A null requirement list represents dynamic routes and deliberately keeps
     * the full discovery path. An empty list is authoritative for a warm cache.
     *
     * @param list<string>|null $requirements
     */
    public function register(?array $requirements = null): void
    {
        if ($this->fullyRegistered) {
            return;
        }

        $required = $requirements === null
            ? null
            : array_fill_keys(array_map(strtolower(...), $requirements), true);

        $authAliases = $required === null ? self::AUTH_ALIASES : array_intersect_key(self::AUTH_ALIASES, $required);
        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => isset($authAliases[$alias]),
            fn(string $alias, string ...$parameters): object => $this->resolveAuthAlias(
                $alias,
                array_values($parameters),
            ),
            'foundation.auth',
        );

        $sessionAliases = $required === null
            ? self::SESSION_ALIASES
            : array_intersect_key(self::SESSION_ALIASES, $required);
        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => isset($sessionAliases[$alias]),
            fn(string $alias): object => match ($alias) {
                'session' => $this->app->make(SessionMiddleware::class),
                'csrf' => $this->app->make(CsrfMiddleware::class),
                default => throw new \LogicException(sprintf(
                    'Unsupported browser session middleware alias "%s".',
                    $alias,
                )),
            },
            'foundation.session',
        );

        $this->app->make(WebrickMiddlewareFactory::class)->registerAliases($requirements);

        $this->fullyRegistered = $requirements === null;
    }

    private function oauthRateLimitStore(): ?string
    {
        $store = $this->app->config()->get('auth.oauth.rate_limit_store');

        return is_string($store) && $store !== '' ? $store : null;
    }

    /**
     * @param string $alias Registered Foundation auth alias.
     * @param list<string> $parameters
     */
    private function resolveAuthAlias(string $alias, array $parameters): object
    {
        return match ($alias) {
            'resolve-auth' => $this->app->make(ResolvePrincipalMiddleware::class),
            'auth' => $this->app->make(AuthMiddleware::class),
            'guest' => $this->app->make(GuestMiddleware::class),
            'verified' => $this->app->make(VerifiedMiddleware::class),
            'mfa' => $this->app->make(MfaRequiredMiddleware::class),
            'recent' => $this->app->make(RecentAuthMiddleware::class),
            'role' => new RoleMiddleware(
                $this->app->make(CurrentPrincipalContext::class),
                $this->app->make(RoleManager::class),
                $this->app->make(AuthResponseFactory::class),
                $parameters,
            ),
            'permission' => new PermissionMiddleware(
                $this->app->make(CurrentPrincipalContext::class),
                $this->app->make(AuthorizerInterface::class),
                $this->app->make(AuthExceptionMapper::class),
                $this->app->make(AuthResponseFactory::class),
                $parameters,
            ),
            'policy' => new PolicyMiddleware(
                $this->app->make(CurrentPrincipalContext::class),
                $this->app->make(AuthorizerInterface::class),
                $this->app->make(AuthExceptionMapper::class),
                $this->app->make(AuthResponseFactory::class),
                $parameters[0] ?? throw new \InvalidArgumentException('Policy middleware requires an ability.'),
                $parameters[1] ?? null,
            ),
            'oauth-throttle' => $this->app->make(OAuthHttpThrottleFactory::class)->forEndpoint(
                $parameters[0] ?? throw new \InvalidArgumentException(
                    'OAuth throttle middleware requires an endpoint name.',
                ),
                $this->app->make(CacheManager::class)->store($this->oauthRateLimitStore()),
            ),
            default => throw new \LogicException(sprintf('Unsupported auth middleware alias "%s".', $alias)),
        };
    }
}
