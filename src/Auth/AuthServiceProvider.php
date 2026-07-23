<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Auth\Authentication\RememberMe\RememberMeManager;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Security\AccessTokenServiceInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\SessionStoreInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Internal\AuthAuthorizationRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthCacheRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthCoreRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthManagerRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthMfaRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthNotificationRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasskeyRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasswordRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthProductionGuard;
use Infocyph\Foundation\Auth\Internal\AuthRuntimeRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Internal\AuthStoreRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthTokenRegistrar;
use Infocyph\Foundation\Auth\Internal\EpicryptConfigResolver;
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
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class AuthServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $container = $app->container();
        $drivers = new AuthDriverResolver($app->config());
        $secrets = new AuthSecretResolver($app);
        $epicrypt = new EpicryptConfigResolver($app);

        new AuthCoreRegistrar($container)->register($drivers);
        new AuthProductionGuard($app)->guard($drivers);
        new AuthStoreRegistrar($app, $container)->register($drivers->storage());
        new AuthCacheRegistrar($app, $container)->register($drivers);
        new AuthPasswordRegistrar($app, $container, $epicrypt)->register($drivers);
        new AuthTokenRegistrar($app, $container, $secrets, $epicrypt)->register($drivers);
        new AuthMfaRegistrar($app, $container, $secrets)->register($drivers);
        new AuthPasskeyRegistrar($app, $container)->register($drivers);
        new AuthNotificationRegistrar($app, $container)->register($drivers);
        new AuthManagerRegistrar($app, $container)->register();
        new AuthAuthorizationRegistrar($app, $container)->register();
        new AuthRuntimeRegistrar($app, $container)->register();

        if ($app->runningInWeb()) {
            $this->registerHttpServices($app);
        }
    }

    private function registerHttpServices(Application $app): void
    {
        $container = $app->container();

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
    }
}
