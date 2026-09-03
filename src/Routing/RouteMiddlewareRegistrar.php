<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Session\Middleware\CsrfMiddleware;
use Infocyph\Foundation\Session\Middleware\SessionMiddleware;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final class RouteMiddlewareRegistrar
{
    /** @var array<string, callable|string> */
    private const array ALIASES = [
        'resolve-auth' => ResolvePrincipalMiddleware::class,
        'auth' => AuthMiddleware::class,
        'guest' => GuestMiddleware::class,
        'verified' => VerifiedMiddleware::class,
        'mfa' => MfaRequiredMiddleware::class,
        'recent' => RecentAuthMiddleware::class,
        'role' => [RouteMiddlewareRuntimeResolver::class, 'role'],
        'permission' => [RouteMiddlewareRuntimeResolver::class, 'permission'],
        'policy' => [RouteMiddlewareRuntimeResolver::class, 'policy'],
        'oauth-scope' => [RouteMiddlewareRuntimeResolver::class, 'oauthScope'],
        'oauth-audience' => [RouteMiddlewareRuntimeResolver::class, 'oauthAudience'],
        'oauth-throttle' => [RouteMiddlewareRuntimeResolver::class, 'oauthThrottle'],
        'session' => SessionMiddleware::class,
        'csrf' => CsrfMiddleware::class,
    ];

    private bool $fullyRegistered = false;

    public function __construct(private readonly WebrickMiddlewareFactory $webrick) {}

    /**
     * Register only middleware aliases required by the selected route topology.
     *
     * A null requirement list represents dynamic development routes. An empty
     * list is authoritative and registers no Foundation route aliases.
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

        foreach (self::ALIASES as $alias => $resolver) {
            if ($required !== null && !isset($required[$alias])) {
                continue;
            }

            MiddlewareAliases::register($alias, $resolver);
        }

        $this->webrick->registerAliases($requirements);
        $this->fullyRegistered = $requirements === null;
    }
}
