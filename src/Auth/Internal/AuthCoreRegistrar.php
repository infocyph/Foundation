<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Auth\Adapter\Uid\UidAuthIdGenerator;
use Infocyph\Foundation\Auth\Contract\Clock\ClockInterface;
use Infocyph\Foundation\Auth\Contract\Id\AuthIdGeneratorInterface;
use Infocyph\Foundation\Auth\Contract\Security\PasswordPolicyInterface;
use Infocyph\Foundation\Auth\Driver\AuthDriverResolver;
use Infocyph\Foundation\Auth\Support\BaselinePasswordPolicy;
use Infocyph\Foundation\Auth\Support\SystemClock;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\DI\Support\ServiceReference;

final readonly class AuthCoreRegistrar
{
    public function __construct(private Container $container) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if (!$this->container->definitions()->has(ClockInterface::class)) {
            $this->container->bind(
                ClockInterface::class,
                FactoryDefinition::construct(SystemClock::class),
                LifetimeEnum::Singleton,
            );
        }
        $this->container->bind(
            AuthDriverResolver::class,
            FactoryDefinition::construct(AuthDriverResolver::class, [
                new ServiceReference(ConfigRepository::class),
            ]),
            LifetimeEnum::Singleton,
        );
        $this->container->bind(
            AuthIdGeneratorInterface::class,
            FactoryDefinition::construct(UidAuthIdGenerator::class, [
                new ServiceReference(ConfigRepository::class),
            ]),
            LifetimeEnum::Singleton,
        );

        if (!$this->container->definitions()->has(PasswordPolicyInterface::class)) {
            $config = $this->container->get(ConfigRepository::class);
            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Password policy configuration must resolve to ConfigRepository.');
            }
            $this->container->bind(
                PasswordPolicyInterface::class,
                FactoryDefinition::construct(BaselinePasswordPolicy::class, [
                    $config->getInt('auth.password_policy.min_length', 12) ?? 12,
                    $config->getInt('auth.password_policy.max_length', 1024) ?? 1024,
                ]),
                LifetimeEnum::Singleton,
            );
        }

        // Resolve once during composition so invalid driver config fails before runtime creation.
        $drivers->summary();
    }
}
