<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Closure;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Notification\AuthNotifierInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordHasherInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordVerifierInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AccountStoreInterface;
use Infocyph\Foundation\Auth\Contract\Storage\AuditEventStoreInterface;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

abstract readonly class AbstractAuthRegistrar
{
    public function __construct(
        protected Application $app,
        protected Container $container,
    ) {}

    protected function accountProvider(): AccountProviderInterface
    {
        return $this->service(AccountProviderInterface::class);
    }

    protected function accountStore(): AccountStoreInterface
    {
        return $this->service(AccountStoreInterface::class);
    }

    protected function alias(string $id, string $target): void
    {
        $this->container->alias($id, $target, LifetimeEnum::Singleton);
    }

    protected function auditStore(): AuditEventStoreInterface
    {
        return $this->service(AuditEventStoreInterface::class);
    }

    protected function boolConfig(string $key, bool $default): bool
    {
        return ValueNormalizer::bool($this->app->config()->get($key, $default), $default);
    }

    protected function clock(): ClockInterface
    {
        return $this->service(ClockInterface::class);
    }

    protected function hasExplicitBinding(string $id): bool
    {
        return $this->container->definitions()->has($id);
    }

    protected function idGenerator(): AuthIdGeneratorInterface
    {
        return $this->service(AuthIdGeneratorInterface::class);
    }

    protected function intConfig(string $key, int $default): int
    {
        return ValueNormalizer::int($this->app->config()->get($key, $default), $default);
    }

    protected function notifier(): AuthNotifierInterface
    {
        return $this->service(AuthNotifierInterface::class);
    }

    protected function nullableString(mixed $value): ?string
    {
        return ValueNormalizer::nullableString($value);
    }

    protected function passwordHasher(): PasswordHasherInterface
    {
        return $this->service(PasswordHasherInterface::class);
    }

    protected function passwordPolicy(): PasswordPolicyInterface
    {
        return $this->service(PasswordPolicyInterface::class);
    }

    protected function passwordVerifier(): PasswordVerifierInterface
    {
        return $this->service(PasswordVerifierInterface::class);
    }

    /**
     * @param class-string $class
     * @param list<scalar|array<array-key, mixed>|ServiceReference|null> $arguments
     */
    protected function recipe(string $id, string $class, array $arguments = []): void
    {
        $this->container->bind(
            $id,
            FactoryDefinition::construct($class, $arguments),
            LifetimeEnum::Singleton,
        );
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

    /** @return ServiceReference */
    protected function ref(string $id): ServiceReference
    {
        return new ServiceReference($id);
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return object
     * @phpstan-return T
     * @psalm-return T
     */
    protected function service(string $id): object
    {
        return $this->container->get($id);
    }

    protected function singleton(string $id, mixed $concrete): void
    {
        if ($concrete instanceof Closure) {
            $this->container->factory($id, $concrete)->singleton();

            return;
        }

        $this->container->bind($id, $concrete, LifetimeEnum::Singleton);
    }

    protected function stringConfig(string $key, string $default): string
    {
        return ValueNormalizer::string($this->app->config()->get($key, $default), $default);
    }

    /** @return list<string> */
    protected function stringList(mixed $value): array
    {
        return ValueNormalizer::stringList($value);
    }
}
