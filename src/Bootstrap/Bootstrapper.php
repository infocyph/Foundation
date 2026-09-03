<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Bootstrap;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Application\ServiceRegistry;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Communication\CommunicationServiceProvider;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\PathServiceProvider;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchServiceProvider;
use Infocyph\Foundation\Logging\LoggingServiceProvider;
use Infocyph\Foundation\Messaging\MessagingServiceProvider;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\RouteFileLoader;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Foundation\Runtime\RuntimeExecutionState;
use Infocyph\Foundation\Security\SecurityServiceProvider;
use Infocyph\Foundation\Session\SessionServiceProvider;
use Infocyph\Foundation\Validation\ValidationServiceProvider;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;

final class Bootstrapper
{
    private const array PROVIDER_GROUPS = ['common', 'web', 'cli', 'worker', 'scheduler'];

    /** @var list<class-string<ServiceProviderInterface>> */
    private const array OPTIONAL_BUILT_INS = [
        DatabaseServiceProvider::class,
        CacheServiceProvider::class,
        SecurityServiceProvider::class,
        FilesystemServiceProvider::class,
        ValidationServiceProvider::class,
        CommunicationServiceProvider::class,
        NotificationServiceProvider::class,
        SessionServiceProvider::class,
        MessagingServiceProvider::class,
    ];

    /** @var list<class-string<ServiceProviderInterface>> */
    private const array WEB_BUILT_INS = [
        LoggingServiceProvider::class,
        JsonDispatchServiceProvider::class,
        RoutingServiceProvider::class,
        HttpServiceProvider::class,
    ];

    public function boot(Application $app): void
    {
        $app->providers()->boot($app);

        if ($app->runningInWeb()
            && $app->has(RouteFileLoader::class)
            && !RouteCachePath::isWarm($app->config())
        ) {
            $app->make(RouteFileLoader::class)->load();
        }
    }

    public function compose(
        ContainerBuilder $builder,
        FoundationBuildContext $context,
        ?ServiceRegistry $registry = null,
    ): ServiceRegistry {
        $registry ??= new ServiceRegistry();
        $registry->add(new PathServiceProvider());

        foreach (self::OPTIONAL_BUILT_INS as $provider) {
            if ($this->providerDependencyAvailable($provider)) {
                $registry->add($this->instantiateProvider($provider));
            }
        }

        $registry->add(new AuthServiceProvider());

        if ($context->runtimeMode === RuntimeMode::Web) {
            foreach (self::WEB_BUILT_INS as $provider) {
                $registry->add($this->instantiateProvider($provider));
            }
        }

        foreach ($this->configuredProviders($context) as $provider) {
            $registry->add($provider);
        }
        foreach ($this->providerFileProviders($context) as $provider) {
            $registry->add($provider);
        }

        $registry->contribute($builder, $context);
        $builder->onScopeLeave(
            $context->runtimeMode->scopeName(),
            static function (string $scope, Container $container): void {
                unset($scope);
                $state = $container->get(RuntimeExecutionState::class);
                if ($state instanceof RuntimeExecutionState) {
                    $state->cleanup(false);
                }
            },
        );

        return $registry;
    }

    private function assertProviderGroup(int|string $group, mixed $entries): void
    {
        if (!is_string($group) || !in_array($group, self::PROVIDER_GROUPS, true)) {
            throw new BootstrapException(sprintf(
                'Configured providers contain unsupported group "%s".',
                (string) $group,
            ));
        }
        if (!is_array($entries)) {
            throw new BootstrapException(sprintf(
                'Configured provider group "%s" must be a provider list.',
                $group,
            ));
        }
    }

    /** @return list<ServiceProviderInterface> */
    private function configuredProviders(FoundationBuildContext $context): array
    {
        $configured = $context->config['providers'] ?? [];
        if (!is_array($configured)) {
            throw new BootstrapException('Configured providers must be a grouped provider array.');
        }
        if ($configured !== [] && array_is_list($configured)) {
            throw new BootstrapException(
                'Configured providers must define common, web, cli, worker, and scheduler provider groups.',
            );
        }

        foreach ($configured as $group => $entries) {
            $this->assertProviderGroup($group, $entries);
        }

        $providers = [];
        foreach (['common', $context->runtimeMode->value] as $group) {
            $entries = $configured[$group] ?? [];
            if (!is_array($entries)) {
                continue;
            }
            foreach ($entries as $provider) {
                $instance = $this->instantiateProvider($provider);
                $providers[$instance::class] = $instance;
            }
        }

        return array_values($providers);
    }

    private function instantiateProvider(mixed $provider): ServiceProviderInterface
    {
        if ($provider instanceof ServiceProviderInterface) {
            return $provider;
        }
        if (!is_string($provider) || $provider === '' || !class_exists($provider)) {
            throw new BootstrapException('Configured provider must be an existing non-empty class name.');
        }
        if (!is_a($provider, ServiceProviderInterface::class, true)) {
            throw new BootstrapException(sprintf(
                'Configured provider "%s" must implement %s.',
                $provider,
                ServiceProviderInterface::class,
            ));
        }

        return new $provider();
    }

    /** @param class-string<ServiceProviderInterface> $provider */
    private function providerDependencyAvailable(string $provider): bool
    {
        $dependency = match ($provider) {
            CacheServiceProvider::class => \Infocyph\CacheLayer\Cache\Cache::class,
            CommunicationServiceProvider::class => \Infocyph\TalkingBytes\Http\HttpClient::class,
            DatabaseServiceProvider::class => \Infocyph\DBLayer\DB::class,
            FilesystemServiceProvider::class => \Infocyph\Pathwise\PathwiseFacade::class,
            MessagingServiceProvider::class => \Infocyph\Omnibus\MessageBus::class,
            SecurityServiceProvider::class => \Infocyph\Epicrypt\Crypto\AeadCipher::class,
            ValidationServiceProvider::class => \Infocyph\ReqShield\Validator::class,
            default => null,
        };

        return $dependency === null || class_exists($dependency);
    }

    /** @return list<ServiceProviderInterface> */
    private function providerFileProviders(FoundationBuildContext $context): array
    {
        if ($context->compiledConfig) {
            return [];
        }

        $config = $context->config;
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
        $basePath = $app['base_path'] ?? null;
        $basePath = is_string($basePath) && $basePath !== ''
            ? rtrim($basePath, DIRECTORY_SEPARATOR)
            : (getcwd() ?: dirname(__DIR__, 2));

        $normalizedPaths = [];
        foreach ($paths as $key => $path) {
            if (is_string($key) && is_string($path) && $path !== '') {
                $normalizedPaths[$key] = $path;
            }
        }

        $providers = [];
        foreach (new ProviderFileLoader(new PathManager($basePath, $normalizedPaths))->providers($context->runtimeMode) as $provider) {
            $instance = $this->instantiateProvider($provider);
            $providers[$instance::class] = $instance;
        }

        return array_values($providers);
    }
}
