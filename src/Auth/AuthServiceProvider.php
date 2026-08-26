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
use Infocyph\Foundation\Auth\Internal\AuthOAuthRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasskeyRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthPasswordRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthProductionGuard;
use Infocyph\Foundation\Auth\Internal\AuthRuntimeRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthSecretResolver;
use Infocyph\Foundation\Auth\Internal\AuthStoreRegistrar;
use Infocyph\Foundation\Auth\Internal\AuthTokenRegistrar;
use Infocyph\Foundation\Auth\Internal\EpicryptTokenPolicyResolver;
use Infocyph\Foundation\Auth\OAuth\Token\OAuthAccessTokenValidator;
use Infocyph\Foundation\Auth\Principal\CurrentPrincipalContext;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\OAuthBearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\PrincipalResolverInterface;
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
        $epicryptTokens = new EpicryptTokenPolicyResolver($app);

        new AuthCoreRegistrar($container)->register($drivers);
        new AuthProductionGuard($app)->guard($drivers);
        new AuthStoreRegistrar($app, $container)->register($drivers->storage());
        new AuthCacheRegistrar($app, $container)->register($drivers);
        new AuthPasswordRegistrar($app, $container)->register($drivers);
        new AuthTokenRegistrar($app, $container, $secrets, $epicryptTokens)->register($drivers);
        new AuthMfaRegistrar($app, $container, $secrets)->register($drivers);
        new AuthPasskeyRegistrar($app, $container)->register($drivers);
        new AuthNotificationRegistrar($app, $container)->register($drivers);
        new AuthManagerRegistrar($app, $container)->register();
        new AuthAuthorizationRegistrar($app, $container)->register();
        new AuthRuntimeRegistrar($app, $container)->register();
        $oauth = new AuthOAuthRegistrar($app, $container);
        $oauth->register();

        if ($app->runningInWeb()) {
            $this->registerHttpServices($app, $oauth->enabled());
        }
    }

    /** @return list<string> */
    private function principalResolverOrder(Application $app, bool $oauthEnabled): array
    {
        $configured = $app->config()->get('auth.http.principal_resolvers', []);
        $order = [];
        if (is_array($configured)) {
            foreach ($configured as $name) {
                if (is_string($name) && $name !== '' && !in_array($name, $order, true)) {
                    $order[] = $name;
                }
            }
        }
        if ($order === []) {
            $order = ['session', 'bearer', 'remember'];
        }
        if (!$oauthEnabled || in_array('oauth_bearer', $order, true)) {
            return $order;
        }

        $bearer = array_search('bearer', $order, true);
        if (is_int($bearer)) {
            array_splice($order, $bearer, 0, ['oauth_bearer']);

            return $order;
        }

        $order[] = 'oauth_bearer';

        return $order;
    }

    private function registerHttpServices(Application $app, bool $oauthEnabled): void
    {
        $container = $app->container();

        $this->bindFactory($container, SessionPrincipalResolver::class, fn() => new SessionPrincipalResolver(
            config: $app->config(),
            sessions: $app->make(SessionStoreInterface::class),
            accounts: $app->make(AccountProviderInterface::class),
            clock: $app->make(ClockInterface::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, BearerTokenPrincipalResolver::class, fn() => new BearerTokenPrincipalResolver(
            config: $app->config(),
            tokens: $app->make(AccessTokenServiceInterface::class),
            accounts: $app->make(AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, RememberMePrincipalResolver::class, fn() => new RememberMePrincipalResolver(
            config: $app->config(),
            rememberMe: $app->make(RememberMeManager::class),
            accounts: $app->make(AccountProviderInterface::class),
        ), LifetimeEnum::Singleton);
        if ($oauthEnabled) {
            $this->bindFactory($container, OAuthBearerTokenPrincipalResolver::class, fn() => new OAuthBearerTokenPrincipalResolver(
                config: $app->config(),
                validator: $app->make(OAuthAccessTokenValidator::class),
            ), LifetimeEnum::Singleton);
        }
        $this->bindFactory($container, RequestPrincipalResolver::class, function () use ($app, $oauthEnabled): RequestPrincipalResolver {
            /** @var array<string, PrincipalResolverInterface> $resolvers */
            $resolvers = [
                'session' => $app->make(SessionPrincipalResolver::class),
                'bearer' => $app->make(BearerTokenPrincipalResolver::class),
                'remember' => $app->make(RememberMePrincipalResolver::class),
            ];
            if ($oauthEnabled) {
                $resolvers['oauth_bearer'] = $app->make(OAuthBearerTokenPrincipalResolver::class);
            }

            return new RequestPrincipalResolver(
                config: $app->config(),
                resolvers: $resolvers,
                order: $this->principalResolverOrder($app, $oauthEnabled),
            );
        }, LifetimeEnum::Singleton);
        $this->bindFactory($container, ResolvePrincipalMiddleware::class, fn() => new ResolvePrincipalMiddleware(
            principals: $app->make(CurrentPrincipalContext::class),
            resolver: $app->make(RequestPrincipalResolver::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, AuthMiddleware::class, fn() => new AuthMiddleware(
            $app->make(CurrentPrincipalContext::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, GuestMiddleware::class, fn() => new GuestMiddleware(
            $app->make(CurrentPrincipalContext::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, VerifiedMiddleware::class, fn() => new VerifiedMiddleware(
            $app->make(CurrentPrincipalContext::class),
            $app->make(AccountProviderInterface::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, MfaRequiredMiddleware::class, fn() => new MfaRequiredMiddleware(
            $app->make(CurrentPrincipalContext::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
        $this->bindFactory($container, RecentAuthMiddleware::class, fn() => new RecentAuthMiddleware(
            $app->make(CurrentPrincipalContext::class),
            $app->make(AuthResponseFactory::class),
        ), LifetimeEnum::Singleton);
    }
}
