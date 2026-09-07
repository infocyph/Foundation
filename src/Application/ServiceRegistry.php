<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\InterMix\DI\ContainerBuilder;

final class ServiceRegistry
{
    /** @var array<class-string<ServiceProviderInterface>, ServiceProviderInterface> */
    private array $providers = [];

    public function add(ServiceProviderInterface $provider): void
    {
        $this->providers[$provider::class] = $provider;
    }

    public function boot(Application $app): void
    {
        foreach ($this->providers as $provider) {
            $provider->boot($app);
        }
    }

    /** @return list<class-string<ServiceProviderInterface>> */
    public function classes(): array
    {
        return array_keys($this->providers);
    }

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        foreach ($this->providers as $provider) {
            $provider->contribute($builder, $context);
        }
    }
}
