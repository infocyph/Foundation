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
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\RouteFileLoader;
use Infocyph\Foundation\Routing\RoutingServiceProvider;
use Infocyph\Foundation\Security\SecurityServiceProvider;
use Infocyph\Foundation\Validation\ValidationServiceProvider;

final class Bootstrapper
{
    /** @var list<class-string> */
    private const array DEFAULT_PROVIDERS = [
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

    /** @var list<class-string> */
    private const array EAGER_PROVIDERS = [
        FilesystemServiceProvider::class,
        RoutingServiceProvider::class,
        HttpServiceProvider::class,
    ];

    public function activateProviderFor(Application $app, string $service): bool
    {
        $provider = $this->providerFor($service);
        if ($provider === null) {
            return false;
        }

        if ($provider === AuthServiceProvider::class) {
            $app->providers()->activate(IdentifierServiceProvider::class, $app);
        }

        return $app->providers()->activate($provider, $app);
    }

    public function boot(Application $app): void
    {
        $app->providers()->boot($app);

        if ($app->has(RouteFileLoader::class) && !RouteCachePath::isWarm($app->config())) {
            $app->make(RouteFileLoader::class)->load();
        }
    }

    public function canProvide(string $service): bool
    {
        return $this->providerFor($service) !== null;
    }

    public function prepare(Application $app): void
    {
        foreach (self::DEFAULT_PROVIDERS as $provider) {
            if (in_array($provider, self::EAGER_PROVIDERS, true)) {
                $app->register($this->instantiateProvider($provider));
            } else {
                $app->providers()->addDeferred($provider);
            }
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

    /**
     * @return class-string<ServiceProviderInterface>|null
     */
    private function providerFor(string $service): ?string
    {
        $aliases = [
            'foundation.auth' => AuthServiceProvider::class,
            'foundation.cache' => CacheServiceProvider::class,
            'foundation.communication' => CommunicationServiceProvider::class,
            'foundation.data' => DataServiceProvider::class,
            'foundation.db' => DatabaseServiceProvider::class,
            'foundation.ids' => IdentifierServiceProvider::class,
            'foundation.notifications' => NotificationServiceProvider::class,
            'foundation.security' => SecurityServiceProvider::class,
            'foundation.uid' => IdentifierServiceProvider::class,
            'foundation.validator' => ValidationServiceProvider::class,
        ];

        return $aliases[$service] ?? match (true) {
            str_starts_with($service, 'Infocyph\\Foundation\\Auth\\') => AuthServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Cache\\') => CacheServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Communication\\') => CommunicationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Data\\') => DataServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Database\\') => DatabaseServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Identifiers\\') => IdentifierServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\Middleware\\'),
            str_starts_with($service, 'Infocyph\\Foundation\\Http\\Resolver\\') => AuthServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Notifications\\') => NotificationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Security\\') => SecurityServiceProvider::class,
            str_starts_with($service, 'Infocyph\\Foundation\\Validation\\') => ValidationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Email\\') => NotificationServiceProvider::class,
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Grpc\\'),
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Http\\'),
            str_starts_with($service, 'Infocyph\\TalkingBytes\\Webhook\\') => CommunicationServiceProvider::class,
            default => null,
        };
    }
}
