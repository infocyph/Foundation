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
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;

final readonly class AuthCoreRegistrar
{
    public function __construct(private ContainerBuilder $builder) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if (!$this->builder->definitions()->has(ClockInterface::class)) {
            $this->builder->singleton(
                ClockInterface::class,
                FactoryDefinition::construct(SystemClock::class),
            );
        }
        $this->builder->singleton(
            AuthDriverResolver::class,
            FactoryDefinition::construct(AuthDriverResolver::class, [
                new ServiceReference(ConfigRepository::class),
            ]),
        );
        $this->builder->singleton(
            AuthIdGeneratorInterface::class,
            FactoryDefinition::construct(UidAuthIdGenerator::class, [
                new ServiceReference(ConfigRepository::class),
            ]),
        );

        if (!$this->builder->definitions()->has(PasswordPolicyInterface::class)) {
            $config = $this->builder->development()->get(ConfigRepository::class);
            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Password policy configuration must resolve to ConfigRepository.');
            }
            $this->builder->singleton(
                PasswordPolicyInterface::class,
                FactoryDefinition::construct(BaselinePasswordPolicy::class, [
                    $config->getInt('auth.password_policy.min_length', 12) ?? 12,
                    $config->getInt('auth.password_policy.max_length', 1024) ?? 1024,
                ]),
            );
        }

        // Force driver normalization during composition so invalid configuration
        // fails before any runtime container is created.
        $validatedDrivers = $drivers->summary();
        unset($validatedDrivers);
    }
}
