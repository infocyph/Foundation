<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\InterMix\DI\ContainerBuilder;

final class ServiceRegistry
{
    private ?FoundationBuildContext $contributedContext = null;

    /** @var array<class-string<ServiceProviderInterface>, ServiceProviderInterface> */
    private array $providers = [];

    private bool $sealed = false;

    public function add(ServiceProviderInterface $provider): void
    {
        if ($this->sealed) {
            throw new \LogicException('Foundation provider registration is closed after graph contribution.');
        }

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
        if ($this->contributedContext !== null && $this->contributedContext !== $context) {
            throw new \LogicException('Foundation provider graph cannot switch build context after contribution.');
        }

        // Seal the exact context and providers before invoking any callback.
        $this->contributedContext = $context;
        $this->sealed = true;

        foreach ($this->providers as $provider) {
            $provider->contribute($builder, $context);
        }
    }
}
