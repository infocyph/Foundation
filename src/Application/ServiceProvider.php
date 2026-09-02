<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Closure;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

abstract class ServiceProvider implements ServiceProviderInterface
{
    /** @var \WeakMap<ContainerBuilder, Application>|null */
    private static ?\WeakMap $buildApplications = null;

    public function boot(Application $app): void {}

    final public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        $this->register(self::buildApplication($builder, $context));
    }

    /**
     * Temporary provider-internal compatibility seam while each provider is
     * converted from Application-driven registration to declarative builder
     * recipes. It is build-time only and never performs runtime discovery.
     */
    abstract public function register(Application $app): void;

    /** @param array<int, string> $tags */
    final protected function bindFactory(
        Container $container,
        string $id,
        Closure $factory,
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $binding = $container->factory($id, $factory);

        match ($lifetime) {
            LifetimeEnum::Singleton => $binding->singleton($tags),
            LifetimeEnum::Scoped => $binding->scoped($tags),
            LifetimeEnum::Transient => $binding->transient($tags),
        };
    }

    /**
     * @param class-string $class
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     * @param array<int, string> $tags
     */
    final protected function bindRecipe(
        Container $container,
        string $id,
        string $class,
        array $arguments = [],
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
        array $tags = [],
    ): void {
        $container->bind(
            $id,
            FactoryDefinition::construct($class, $arguments),
            $lifetime,
            $tags,
        );
    }

    final protected function hasExplicitBinding(Container $container, string $id): bool
    {
        return $container->definitions()->has($id);
    }

    private static function buildApplication(
        ContainerBuilder $builder,
        FoundationBuildContext $context,
    ): Application {
        self::$buildApplications ??= new \WeakMap();
        $existing = self::$buildApplications[$builder] ?? null;
        if ($existing instanceof Application) {
            return $existing;
        }

        $app = new Application(
            config: new ConfigRepository($context->config, $context->compiledConfig),
            container: $builder->development(),
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
            runtimeMode: $context->runtimeMode,
            bindDevelopmentCore: true,
            enableDynamicProviderActivation: false,
        );
        self::$buildApplications[$builder] = $app;

        return $app;
    }
}
