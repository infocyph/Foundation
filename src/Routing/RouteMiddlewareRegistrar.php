<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Config\ConfigRepository;
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
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final class RouteMiddlewareRegistrar
{
    private bool $registered = false;

    public function __construct(
        private readonly Application $app,
        private readonly ConfigRepository $config,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        MiddlewareAliases::register('resolve-auth', fn() => $this->app->make(ResolvePrincipalMiddleware::class));
        MiddlewareAliases::register('auth', fn() => $this->app->make(AuthMiddleware::class));
        MiddlewareAliases::register('guest', fn() => $this->app->make(GuestMiddleware::class));
        MiddlewareAliases::register('verified', fn() => $this->app->make(VerifiedMiddleware::class));
        MiddlewareAliases::register('mfa', fn() => $this->app->make(MfaRequiredMiddleware::class));
        MiddlewareAliases::register('recent', fn() => $this->app->make(RecentAuthMiddleware::class));
        MiddlewareAliases::register('role', fn(string ...$roles) => new RoleMiddleware(
            $this->app->make(CurrentPrincipalContext::class),
            $this->app->make(RoleManager::class),
            $this->app->make(AuthResponseFactory::class),
            array_values($roles),
        ));
        MiddlewareAliases::register('permission', fn(string ...$abilities) => new PermissionMiddleware(
            $this->app->make(CurrentPrincipalContext::class),
            $this->app->make(AuthorizerInterface::class),
            $this->app->make(AuthExceptionMapper::class),
            $this->app->make(AuthResponseFactory::class),
            array_values($abilities),
        ));
        MiddlewareAliases::register('policy', fn(string $ability, string ...$resourceKey) => new PolicyMiddleware(
            $this->app->make(CurrentPrincipalContext::class),
            $this->app->make(AuthorizerInterface::class),
            $this->app->make(AuthExceptionMapper::class),
            $this->app->make(AuthResponseFactory::class),
            $ability,
            $resourceKey[0] ?? null,
        ));
        $this->app->make(WebrickMiddlewareFactory::class)->registerAliases();

        foreach ($this->configuredAliases() as $alias => $class) {
            MiddlewareAliases::register($alias, $class);
        }

        $this->registered = true;
    }

    /**
     * @return array<string, string>
     */
    private function configuredAliases(): array
    {
        $configured = $this->config->get('router.middleware', []);
        if (!is_array($configured)) {
            return [];
        }

        $aliases = [];
        foreach ($configured as $alias => $class) {
            if (!is_string($alias) || !is_string($class) || $alias === '' || $class === '') {
                continue;
            }

            $aliases[$alias] = $class;
        }

        return $aliases;
    }
}
