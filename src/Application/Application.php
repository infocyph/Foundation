<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\ContainerFactory;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Notifications\NotificationManager;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Security\SecurityManager;
use Infocyph\Foundation\Validation\ValidationManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class Application
{
    private bool $booted = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
        private readonly ServiceRegistry $providers,
        private readonly Bootstrapper $bootstrapper,
    ) {
        $this->bindCoreServices();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config = []): self
    {
        $repository = (new ConfigLoader())->load($config);
        $container = (new ContainerFactory())->create(
            is_string($repository->get('app.container_alias'))
                ? $repository->get('app.container_alias')
                : null,
        );

        $app = new self(
            config: $repository,
            container: $container,
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
        );

        $app->bootstrapper->prepare($app);

        return $app;
    }

    public function auth(): AuthServices
    {
        return $this->make(AuthServices::class);
    }

    public function authManager(): AuthManager
    {
        return $this->make(AuthManager::class);
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->bootstrapper->boot($this);
        $this->booted = true;

        return $this;
    }

    public function cache(): CacheManager
    {
        return $this->service('foundation.cache');
    }

    public function config(): ConfigRepository
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function db(): DatabaseManager
    {
        return $this->service('foundation.db');
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function make(string $id): mixed
    {
        try {
            return $this->container->get($id);
        } catch (\Throwable $e) {
            throw new ServiceResolutionException(sprintf('Unable to resolve service "%s".', $id), previous: $e);
        }
    }

    public function notifications(): NotificationManager
    {
        return $this->service('foundation.notifications');
    }

    public function paths(): PathManager
    {
        return $this->service('foundation.paths');
    }

    public function providers(): ServiceRegistry
    {
        return $this->providers;
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $this->providers->add($provider);

        return $this;
    }

    public function router(): RouterManager
    {
        return $this->service('foundation.router');
    }

    public function security(): SecurityManager
    {
        return $this->service('foundation.security');
    }

    public function validator(): ValidationManager
    {
        return $this->service('foundation.validator');
    }

    private function bindCoreServices(): void
    {
        $this->container->bind(self::class, $this, LifetimeEnum::Singleton);
        $this->container->bind(ConfigRepository::class, $this->config, LifetimeEnum::Singleton);
        $this->container->bind(Container::class, $this->container, LifetimeEnum::Singleton);
    }

    /**
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    private function service(string $id): mixed
    {
        return $this->make($id);
    }
}
