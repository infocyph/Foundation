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

final readonly class RouteMiddlewareRegistrar
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
        'maintenance' => [RouteMiddlewareRuntimeResolver::class, 'maintenance'],
        'signed' => [RouteMiddlewareRuntimeResolver::class, 'signed'],
        'session' => SessionMiddleware::class,
        'csrf' => CsrfMiddleware::class,
    ];

    public function __construct(private WebrickMiddlewareFactory $webrick) {}

    /**
     * Register only middleware aliases required by the selected route topology.
     *
     * A null requirement list represents dynamic development routes. An empty
     * list is authoritative and registers no Foundation route aliases.
     *
     * Registration is intentionally idempotent at the process-registry level.
     * Do not cache registration state on this object: Webrick's build/test
     * registry may be reset independently of the Foundation service instance.
     *
     * @param list<string>|null $requirements
     */
    public function register(?array $requirements = null): void
    {
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
    }
}
