<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

final class ServiceRegistry
{
    /**
     * @var array<string, ServiceProviderInterface>
     */
    private array $providers = [];

    /**
     * @var array<string, true>
     */
    private array $registered = [];

    /**
     * @var array<string, true>
     */
    private array $booted = [];

    public function add(ServiceProviderInterface $provider): void
    {
        $this->providers[$provider::class] = $provider;
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
}
