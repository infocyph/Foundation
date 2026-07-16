<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
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

        $container->bind(WebrickMiddlewareFactory::class, fn() => new WebrickMiddlewareFactory(
            app: $app,
            config: $app->config(),
        ), LifetimeEnum::Singleton);
        $container->bind(WebrickRouterFactory::class, fn() => new WebrickRouterFactory(
            $app->config(),
            $app->make(WebrickMiddlewareFactory::class),
        ), LifetimeEnum::Singleton);

        $container->bind(AuthResponseFactory::class, new AuthResponseFactory(), LifetimeEnum::Singleton);
        $container->bind(AuthExceptionMapper::class, fn() => new AuthExceptionMapper(
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(SessionPrincipalResolver::class, fn() => new SessionPrincipalResolver(
            config: $app->config(),
            sessions: $app->make(SessionStoreInterface::class),
            accounts: $app->make(AccountProviderInterface::class),
            clock: $app->make(ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(BearerTokenPrincipalResolver::class, fn() => new BearerTokenPrincipalResolver(
            config: $app->config(),
            tokens: $app->make(AccessTokenServiceInterface::class),
            accounts: $app->make(AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RememberMePrincipalResolver::class, fn() => new RememberMePrincipalResolver(
            config: $app->config(),
            rememberMe: $app->make(RememberMeManager::class),
            accounts: $app->make(AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RequestPrincipalResolver::class, fn() => new RequestPrincipalResolver(
            config: $app->config(),
            resolvers: [
                'session' => $app->make(SessionPrincipalResolver::class),
                'bearer' => $app->make(BearerTokenPrincipalResolver::class),
                'remember' => $app->make(RememberMePrincipalResolver::class),
            ],
        ), LifetimeEnum::Singleton);
        $container->bind(ResolvePrincipalMiddleware::class, fn() => new ResolvePrincipalMiddleware(
            principals: $app->authManager()->principal(),
            resolver: $app->make(RequestPrincipalResolver::class),
        ), LifetimeEnum::Singleton);
        $container->bind(AuthMiddleware::class, fn() => new AuthMiddleware(
            $app->authManager()->principal(),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(GuestMiddleware::class, fn() => new GuestMiddleware(
            $app->authManager()->principal(),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(VerifiedMiddleware::class, fn() => new VerifiedMiddleware(
            $app->authManager()->principal(),
            $app->make(AccountProviderInterface::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(MfaRequiredMiddleware::class, fn() => new MfaRequiredMiddleware(
            $app->authManager()->principal(),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RecentAuthMiddleware::class, fn() => new RecentAuthMiddleware(
            $app->authManager()->principal(),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $container->bind(RouteMiddlewareRegistrar::class, fn() => new RouteMiddlewareRegistrar($app), LifetimeEnum::Singleton);
        $container->bind(RoutePresetRegistrar::class, fn() => new RoutePresetRegistrar(
            $app->make(RouteMiddlewareRegistrar::class),
            $app->config(),
        ), LifetimeEnum::Singleton);
        $container->bind(RouteFileLoader::class, fn() => new RouteFileLoader(
            paths: $app->make(PathManager::class),
            config: $app->config(),
            router: $app->make(RouterManager::class),
            files: $this->routeFiles($app->config()->get('router.files', ['web.php', 'api.php', 'auth.php'])),
        ), LifetimeEnum::Singleton);

        $container->bind(RouterManager::class, fn() => new RouterManager(
            config: $app->config(),
            factory: $app->make(WebrickRouterFactory::class),
            presets: $app->make(RoutePresetRegistrar::class),
        ), LifetimeEnum::Singleton);

        $container->bind('foundation.router', fn() => $container->get(RouterManager::class), LifetimeEnum::Singleton);
    }

    /**
     * @return list<string>
     */
    private function routeFiles(mixed $value): array
    {
        if (!is_array($value)) {
            return ['web.php', 'api.php', 'auth.php'];
        }

        $files = [];

        foreach ($value as $file) {
            if (!is_string($file) || $file === '') {
                continue;
            }

            $files[] = $file;
        }

        return $files === [] ? ['web.php', 'api.php', 'auth.php'] : $files;
    }
}
