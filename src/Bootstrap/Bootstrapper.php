<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Bootstrap;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProviderInterface;
use Infocyph\Foundation\Auth\AuthServiceProvider;
use Infocyph\Foundation\Cache\CacheServiceProvider;
use Infocyph\Foundation\Database\DatabaseServiceProvider;
use Infocyph\Foundation\Exception\BootstrapException;
use Infocyph\Foundation\Filesystem\FilesystemServiceProvider;
use Infocyph\Foundation\Notifications\NotificationServiceProvider;
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
        NotificationServiceProvider::class,
        FilesystemServiceProvider::class,
        ValidationServiceProvider::class,
        RoutingServiceProvider::class,
        AuthServiceProvider::class,
    ];

    public function prepare(Application $app): void
    {
        foreach ($this->providersFor($app) as $provider) {
            $app->register($provider);
        }
    }

    public function boot(Application $app): void
    {
        $app->providers()->boot($app);
    }

    /**
     * @return list<ServiceProviderInterface>
     */
    private function providersFor(Application $app): array
    {
        $configured = $app->config()->get('providers', []);
        if (!is_array($configured)) {
            $configured = [];
        }

        $providers = [];

        foreach ([...$this->defaultProviders, ...$configured] as $provider) {
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
}
