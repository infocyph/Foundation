<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

final class ServiceRegistry
{
    /**
     * @var array<string, true>
     */
    private array $booted = [];

    /**
     * @var array<class-string, ServiceProviderInterface|class-string<ServiceProviderInterface>>
     */
    private array $deferred = [];

    /**
     * @var array<string, ServiceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string, true>
     */
    private array $registered = [];

    /**
     * @param class-string<ServiceProviderInterface> $provider
     */
    public function activate(string $provider, Application $app): bool
    {
        $deferred = $this->deferred[$provider] ?? null;
        if ($deferred === null) {
            return isset($this->providers[$provider]);
        }

        $instance = is_string($deferred) ? new $deferred() : $deferred;

        unset($this->deferred[$provider]);
        $this->providers[$provider] = $instance;
        $instance->register($app);
        $this->registered[$provider] = true;

        if ($app->booted()) {
            $instance->boot($app);
            $this->booted[$provider] = true;
        }

        return true;
    }

    public function add(ServiceProviderInterface $provider): void
    {
        $this->providers[$provider::class] = $provider;
        unset($this->deferred[$provider::class]);
    }

    /** @param ServiceProviderInterface|class-string<ServiceProviderInterface> $provider */
    public function addDeferred(ServiceProviderInterface|string $provider): void
    {
        $class = $provider instanceof ServiceProviderInterface ? $provider::class : $provider;

        if (!isset($this->providers[$class])) {
            $this->deferred[$class] = $provider;
        }
    }

    public function boot(Application $app): void
    {
        $this->register($app);

        foreach ($this->providers as $provider) {
            if (isset($this->booted[$provider::class])) {
                continue;
            }

            $provider->boot($app);
            $this->booted[$provider::class] = true;
        }
    }

    public function register(Application $app): void
    {
        foreach ($this->providers as $provider) {
            if (isset($this->registered[$provider::class])) {
                continue;
            }

            $provider->register($app);
            $this->registered[$provider::class] = true;
        }
    }
}
