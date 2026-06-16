<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Auth\Authorization\Gate\AuthorizerInterface;
use Infocyph\Foundation\Auth\Authorization\Role\RoleManager;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
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
use Infocyph\InterMix\DI\Container;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final class RouteMiddlewareRegistrar
{
    private bool $registered = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
    ) {}

    public function register(): void
    {
        if ($this->registered) {
            return;
        }

        MiddlewareAliases::register('resolve-auth', fn() => $this->container->get(ResolvePrincipalMiddleware::class));
        MiddlewareAliases::register('auth', fn() => $this->container->get(AuthMiddleware::class));
        MiddlewareAliases::register('guest', fn() => $this->container->get(GuestMiddleware::class));
        MiddlewareAliases::register('verified', fn() => $this->container->get(VerifiedMiddleware::class));
        MiddlewareAliases::register('mfa', fn() => $this->container->get(MfaRequiredMiddleware::class));
        MiddlewareAliases::register('recent', fn() => $this->container->get(RecentAuthMiddleware::class));
        MiddlewareAliases::register('role', fn(string ...$roles) => new RoleMiddleware(
            $this->container->get(CurrentPrincipalContext::class),
            $this->container->get(RoleManager::class),
            $this->container->get(AuthResponseFactory::class),
            $roles,
        ));
        MiddlewareAliases::register('permission', fn(string ...$abilities) => new PermissionMiddleware(
            $this->container->get(CurrentPrincipalContext::class),
            $this->container->get(AuthorizerInterface::class),
            $this->container->get(AuthExceptionMapper::class),
            $this->container->get(AuthResponseFactory::class),
            $abilities,
        ));
        MiddlewareAliases::register('policy', function (string $ability, string ...$resourceKey) {
            return new PolicyMiddleware(
                $this->container->get(CurrentPrincipalContext::class),
                $this->container->get(AuthorizerInterface::class),
                $this->container->get(AuthExceptionMapper::class),
                $this->container->get(AuthResponseFactory::class),
                $ability,
                $resourceKey[0] ?? null,
            );
        });

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
