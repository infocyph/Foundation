<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

final class ServiceRegistry
{
    /** @var array<string, true> */
    private array $booted = [];

    /** @var array<class-string, ServiceProviderInterface|class-string<ServiceProviderInterface>> */
    private array $deferred = [];

    /** @var array<string, ServiceProviderInterface> */
    private array $providers = [];

    /** @var array<string, true> */
    private array $registered = [];

    /** @param class-string<ServiceProviderInterface> $provider */
    public function activate(string $provider, Application $app): bool
    {
        $instance = $this->providers[$provider] ?? null;
        if ($instance instanceof ServiceProviderInterface) {
            $this->registerProvider($instance, $app);

            if ($app->booted()) {
                $this->bootProvider($instance, $app);
            }

            return true;
        }

        $deferred = $this->deferred[$provider] ?? null;
        if ($deferred === null) {
            return false;
        }

        $instance = is_string($deferred) ? new $deferred() : $deferred;
        $this->providers[$provider] = $instance;
        unset($this->deferred[$provider]);

        try {
            $this->registerProvider($instance, $app);
            if ($app->booted()) {
                $this->bootProvider($instance, $app);
            }
        } catch (\Throwable $exception) {
            unset($this->providers[$provider], $this->registered[$provider], $this->booted[$provider]);
            $this->deferred[$provider] = $deferred;

            throw $exception;
        }

        return true;
    }

    public function add(ServiceProviderInterface $provider): void
    {
        $class = $provider::class;
        if (isset($this->providers[$class])) {
            return;
        }

        $this->providers[$class] = $provider;
        unset($this->deferred[$class]);
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
            $this->bootProvider($provider, $app);
        }
    }

    public function register(Application $app): void
    {
        foreach ($this->providers as $provider) {
            $this->registerProvider($provider, $app);
        }
    }

    private function bootProvider(ServiceProviderInterface $provider, Application $app): void
    {
        $class = $provider::class;
        if (isset($this->booted[$class])) {
            return;
        }

        $provider->boot($app);
        $this->booted[$class] = true;
    }

    private function registerProvider(ServiceProviderInterface $provider, Application $app): void
    {
        $class = $provider::class;
        if (isset($this->registered[$class])) {
            return;
        }

        $provider->register($app);
        $this->registered[$class] = true;
    }
}
