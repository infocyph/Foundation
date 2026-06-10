<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Middleware\RoleMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RememberMePrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\SessionPrincipalResolver;
use Infocyph\Foundation\Http\Response\AuthExceptionMapper;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class RoutingServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();

        $container->bind(WebrickRouterFactory::class, fn() => new WebrickRouterFactory(
            $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind(AuthResponseFactory::class, new AuthResponseFactory(), LifetimeEnum::Singleton);
        $container->bind(AuthExceptionMapper::class, fn() => new AuthExceptionMapper(
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(SessionPrincipalResolver::class, fn() => new SessionPrincipalResolver(
            config: $app->config(),
            sessions: $container->get(\Infocyph\AuthLayer\Contract\Storage\SessionStoreInterface::class),
            accounts: $container->get(\Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface::class),
            clock: $container->get(\Infocyph\AuthLayer\Contract\Clock\ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(BearerTokenPrincipalResolver::class, fn() => new BearerTokenPrincipalResolver(
            config: $app->config(),
            tokens: $container->get(\Infocyph\AuthLayer\Contract\Security\AccessTokenServiceInterface::class),
            accounts: $container->get(\Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RememberMePrincipalResolver::class, fn() => new RememberMePrincipalResolver(
            config: $app->config(),
            rememberMe: $container->get(\Infocyph\AuthLayer\Authentication\RememberMe\RememberMeManager::class),
            accounts: $container->get(\Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RequestPrincipalResolver::class, fn() => new RequestPrincipalResolver(
            config: $app->config(),
            resolvers: [
                'session' => $container->get(SessionPrincipalResolver::class),
                'bearer' => $container->get(BearerTokenPrincipalResolver::class),
                'remember' => $container->get(RememberMePrincipalResolver::class),
            ],
        ), LifetimeEnum::Singleton);
        $container->bind(ResolvePrincipalMiddleware::class, fn() => new ResolvePrincipalMiddleware(
            principals: $app->authManager()->principal(),
            resolver: $container->get(RequestPrincipalResolver::class),
        ), LifetimeEnum::Singleton);
        $container->bind(AuthMiddleware::class, fn() => new AuthMiddleware(
            $app->authManager()->principal(),
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(GuestMiddleware::class, fn() => new GuestMiddleware(
            $app->authManager()->principal(),
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(VerifiedMiddleware::class, fn() => new VerifiedMiddleware(
            $app->authManager()->principal(),
            $app->make(\Infocyph\AuthLayer\Contract\Storage\AccountProviderInterface::class),
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(MfaRequiredMiddleware::class, fn() => new MfaRequiredMiddleware(
            $app->authManager()->principal(),
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RecentAuthMiddleware::class, fn() => new RecentAuthMiddleware(
            $app->authManager()->principal(),
            $container->get(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RouteMiddlewareRegistrar::class, fn() => new RouteMiddlewareRegistrar(
            $app->config(),
            $container,
        ), LifetimeEnum::Singleton);
        $container->bind(RoutePresetRegistrar::class, fn() => new RoutePresetRegistrar(
            $container->get(RouteMiddlewareRegistrar::class),
            $app->config(),
        ), LifetimeEnum::Singleton);

        $container->bind(RouterManager::class, fn() => new RouterManager(
            config: $app->config(),
            factory: $container->get(WebrickRouterFactory::class),
            presets: $container->get(RoutePresetRegistrar::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.router', fn() => $container->get(RouterManager::class), LifetimeEnum::Singleton);
    }
}
