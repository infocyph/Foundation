<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

abstract readonly class AbstractAuthRegistrar
{
    public function __construct(
        protected ConfigRepository $config,
        protected ContainerBuilder $builder,
    ) {}

    protected function alias(string $id, string $target): void
    {
        $this->builder->alias($id, $target);
    }

    protected function boolConfig(string $key, bool $default): bool
    {
        return ValueNormalizer::bool($this->config->get($key, $default), $default);
    }

    protected function intConfig(string $key, int $default): int
    {
        return ValueNormalizer::int($this->config->get($key, $default), $default);
    }

    protected function nullableString(mixed $value): ?string
    {
        return ValueNormalizer::nullableString($value);
    }

    /**
     * @param class-string $class
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    protected function recipe(
        string $id,
        string $class,
        array $arguments = [],
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
    ): void {
        $this->builder->factory(
            $id,
            FactoryDefinition::construct($class, $arguments),
            $lifetime,
        );
    }

    protected function ref(string $id): ServiceReference
    {
        return new ServiceReference($id);
    }

    /** @param class-string $class */
    protected function requirePackage(string $class, string $package, string $module): void
    {
        if (class_exists($class) || interface_exists($class)) {
            return;
        }

        throw new \LogicException(sprintf(
            'The selected auth driver requires %s; run "php infbyte module:install %s".',
            $package,
            $module,
        ));
    }

    /**
     * @param class-string $factoryClass
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    protected function staticRecipe(
        string $id,
        string $factoryClass,
        string $method,
        array $arguments = [],
        LifetimeEnum $lifetime = LifetimeEnum::Singleton,
    ): void {
        $this->builder->factory(
            $id,
            FactoryDefinition::staticFactory($factoryClass, $method, $arguments),
            $lifetime,
        );
    }

    protected function stringConfig(string $key, string $default): string
    {
        return ValueNormalizer::string($this->config->get($key, $default), $default);
    }

    /** @return list<string> */
    protected function stringList(mixed $value): array
    {
        return ValueNormalizer::stringList($value);
    }
}
