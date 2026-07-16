<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Bootstrap;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ProviderFileLoader;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Communication\CommunicationServiceProvider;
use Infocyph\Foundation\Data\DataServiceProvider;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\HttpServiceProvider;
use Infocyph\Foundation\Identifiers\IdentifierServiceProvider;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
use Infocyph\Foundation\Routing\RouteFileLoader;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Foundation\Security\SecurityServiceProvider;
use Infocyph\Foundation\Validation\ValidationServiceProvider;

final class Bootstrapper
{
    /**
     * @var list<class-string>
     */
    private array $defaultProviders = [
        CacheServiceProvider::class,
        DatabaseServiceProvider::class,
        SecurityServiceProvider::class,
        CommunicationServiceProvider::class,
        NotificationServiceProvider::class,
        FilesystemServiceProvider::class,
        DataServiceProvider::class,
        IdentifierServiceProvider::class,
        ValidationServiceProvider::class,
        RoutingServiceProvider::class,
        HttpServiceProvider::class,
        AuthServiceProvider::class,
    ];

    public function boot(Application $app): void
    {
        $app->providers()->boot($app);

        if ($app->has(RouteFileLoader::class)) {
            $app->make(RouteFileLoader::class)->load();
        }
    }

    public function prepare(Application $app): void
    {
        foreach ($this->defaultProviders() as $provider) {
            $app->register($provider);
        }

        $app->providers()->register($app);

        foreach ($this->configuredProviders($app) as $provider) {
            $app->register($provider);
        }

        foreach ($this->providerFileProviders($app) as $provider) {
            $app->register($provider);
        }

        $app->providers()->register($app);
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private function configuredProviders(Application $app): array
    {
        $configured = $app->config()->get('providers', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $providers = [];

        foreach ($configured as $provider) {
            $instance = $this->instantiateProvider($provider);
            $providers[$instance::class] = $instance;
        }

        return array_values($providers);
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private function defaultProviders(): array
    {
        $providers = [];

        foreach ($this->defaultProviders as $provider) {
            $instance = $this->instantiateProvider($provider);
            $providers[$instance::class] = $instance;
        }

        return array_values($providers);
    }

    private function instantiateProvider(mixed $provider): ServiceProviderInterface
    {
        if ($provider instanceof ServiceProviderInterface) {
            return $provider;
        }

        if (!is_string($provider) || $provider === '') {
            throw new BootstrapException('Configured provider must be a non-empty class name.');
        }

        if (!class_exists($provider)) {
            throw new BootstrapException(sprintf('Configured provider "%s" was not found.', $provider));
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

    /**
     * @return list<ServiceProviderInterface>
     */
    private function providerFileProviders(Application $app): array
    {
        $loader = new ProviderFileLoader($app->make(PathManager::class));
        $providers = [];

        foreach ($loader->providers() as $provider) {
            $instance = $this->instantiateProvider($provider);
            $providers[$instance::class] = $instance;
        }

        return array_values($providers);
    }
}
