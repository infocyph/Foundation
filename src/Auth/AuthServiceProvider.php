<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth;

use Infocyph\Foundation\Application\FoundationBuildContext;
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
use Infocyph\Foundation\Auth\Internal\AuthHttpGraphFactory;
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
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Http\Middleware\AuthMiddleware;
use Infocyph\Foundation\Http\Middleware\GuestMiddleware;
use Infocyph\Foundation\Http\Middleware\MfaRequiredMiddleware;
use Infocyph\Foundation\Http\Middleware\RecentAuthMiddleware;
use Infocyph\Foundation\Http\Middleware\ResolvePrincipalMiddleware;
use Infocyph\Foundation\Http\Middleware\VerifiedMiddleware;
use Infocyph\Foundation\Http\Resolver\BearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\OAuthBearerTokenPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RememberMePrincipalResolver;
use Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver;
use Infocyph\Foundation\Http\Resolver\SessionPrincipalResolver;
use Infocyph\Foundation\Http\Response\AuthResponseFactory;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

final class AuthServiceProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $app = $this->application($builder, $context);
        $drivers = new AuthDriverResolver($app->config());
        $secrets = new AuthSecretResolver($app);
        $epicryptTokens = new EpicryptTokenPolicyResolver($app);

        new AuthCoreRegistrar($builder)->register($drivers);
        new AuthProductionGuard($app)->guard($drivers);
        new AuthStoreRegistrar($app, $builder)->register($drivers->storage());
        new AuthCacheRegistrar($app, $builder)->register($drivers);
        new AuthPasswordRegistrar($app, $builder)->register($drivers);
        new AuthTokenRegistrar($app, $builder, $secrets, $epicryptTokens)->register($drivers);
        new AuthMfaRegistrar($app, $builder, $secrets)->register($drivers);
        new AuthPasskeyRegistrar($app, $builder)->register($drivers);
        new AuthNotificationRegistrar($app, $builder)->register($drivers);
        new AuthManagerRegistrar($app, $builder)->register();
        new AuthAuthorizationRegistrar($app, $builder)->register();
        new AuthRuntimeRegistrar($app, $builder)->register();
        $oauth = new AuthOAuthRegistrar($app, $builder);
        $oauth->register();

        if ($context->runtimeMode->value === 'web') {
            $this->contributeHttp($builder, $context, $oauth->enabled());
        }

        $builder->alias('foundation.auth', AuthDriverResolver::class);
    }

    private function contributeHttp(
        ContainerBuilder $builder,
        FoundationBuildContext $context,
        bool $oauthEnabled,
    ): void {
        $builder->singleton(SessionPrincipalResolver::class, FactoryDefinition::construct(
            SessionPrincipalResolver::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(SessionStoreInterface::class),
                new ServiceReference(AccountProviderInterface::class),
                new ServiceReference(ClockInterface::class),
            ],
        ));
        $builder->singleton(BearerTokenPrincipalResolver::class, FactoryDefinition::construct(
            BearerTokenPrincipalResolver::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(AccessTokenServiceInterface::class),
                new ServiceReference(AccountProviderInterface::class),
            ],
        ));
        $builder->singleton(RememberMePrincipalResolver::class, FactoryDefinition::construct(
            RememberMePrincipalResolver::class,
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(RememberMeManager::class),
                new ServiceReference(AccountProviderInterface::class),
            ],
        ));
        if ($oauthEnabled) {
            $builder->singleton(OAuthBearerTokenPrincipalResolver::class, FactoryDefinition::construct(
                OAuthBearerTokenPrincipalResolver::class,
                [
                    new ServiceReference(ConfigRepository::class),
                    new ServiceReference(OAuthAccessTokenValidator::class),
                ],
            ));
        }

        $builder->singleton(RequestPrincipalResolver::class, FactoryDefinition::staticFactory(
            AuthHttpGraphFactory::class,
            'requestPrincipalResolver',
            [
                new ServiceReference(ConfigRepository::class),
                new ServiceReference(SessionPrincipalResolver::class),
                new ServiceReference(BearerTokenPrincipalResolver::class),
                new ServiceReference(RememberMePrincipalResolver::class),
                $oauthEnabled ? new ServiceReference(OAuthBearerTokenPrincipalResolver::class) : null,
                $this->principalResolverOrder($context, $oauthEnabled),
            ],
        ));
        $builder->singleton(ResolvePrincipalMiddleware::class, FactoryDefinition::construct(
            ResolvePrincipalMiddleware::class,
            [new ServiceReference(CurrentPrincipalContext::class), new ServiceReference(RequestPrincipalResolver::class)],
        ));
        $builder->singleton(AuthMiddleware::class, FactoryDefinition::construct(
            AuthMiddleware::class,
            [new ServiceReference(CurrentPrincipalContext::class), new ServiceReference(AuthResponseFactory::class)],
        ));
        $builder->singleton(GuestMiddleware::class, FactoryDefinition::construct(
            GuestMiddleware::class,
            [new ServiceReference(CurrentPrincipalContext::class), new ServiceReference(AuthResponseFactory::class)],
        ));
        $builder->singleton(VerifiedMiddleware::class, FactoryDefinition::construct(
            VerifiedMiddleware::class,
            [
                new ServiceReference(CurrentPrincipalContext::class),
                new ServiceReference(AccountProviderInterface::class),
                new ServiceReference(AuthResponseFactory::class),
            ],
        ));
        $builder->singleton(MfaRequiredMiddleware::class, FactoryDefinition::construct(
            MfaRequiredMiddleware::class,
            [new ServiceReference(CurrentPrincipalContext::class), new ServiceReference(AuthResponseFactory::class)],
        ));
        $builder->singleton(RecentAuthMiddleware::class, FactoryDefinition::construct(
            RecentAuthMiddleware::class,
            [new ServiceReference(CurrentPrincipalContext::class), new ServiceReference(AuthResponseFactory::class)],
        ));
    }

    /** @return list<string> */
    private function principalResolverOrder(FoundationBuildContext $context, bool $oauthEnabled): array
    {
        $auth = is_array($context->config['auth'] ?? null) ? $context->config['auth'] : [];
        $http = is_array($auth['http'] ?? null) ? $auth['http'] : [];
        $order = $this->resolverNames($http['principal_resolvers'] ?? null);
        if ($order === []) {
            $order = ['session', 'bearer', 'remember'];
        }

        return $this->withOAuthResolver($order, $oauthEnabled);
    }

    /** @return list<string> */
    private function resolverNames(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $order = [];
        foreach ($configured as $name) {
            if (is_string($name) && $name !== '' && !in_array($name, $order, true)) {
                $order[] = $name;
            }
        }

        return $order;
    }

    /**
     * @param list<string> $order
     * @return list<string>
     */
    private function withOAuthResolver(array $order, bool $oauthEnabled): array
    {
        if (!$oauthEnabled || in_array('oauth_bearer', $order, true)) {
            return $order;
        }

        $bearer = array_search('bearer', $order, true);
        if ($bearer === false) {
            $order[] = 'oauth_bearer';

            return $order;
        }

        array_splice($order, $bearer, 0, ['oauth_bearer']);

        return $order;
    }
}
