<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
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
    ];

    /** @var array<string, true> */
    private const array SESSION_ALIASES = [
        'session' => true,
        'csrf' => true,
    ];

    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => isset(self::AUTH_ALIASES[$alias]),
            fn(string $alias, string ...$parameters): object => $this->resolveAuthAlias(
                $alias,
                array_values($parameters),
            ),
            'foundation.auth',
        );
        MiddlewareAliases::registerResolver(
            static fn(string $alias): bool => isset(self::SESSION_ALIASES[$alias]),
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
        $this->app->make(WebrickMiddlewareFactory::class)->registerAliases();

        $this->registered = true;
    }

    /**
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
            default => throw new \LogicException(sprintf('Unsupported auth middleware alias "%s".', $alias)),
        };
    }
}
