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
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final readonly class AuthCoreRegistrar
{
    public function __construct(private Container $container) {}

    public function register(AuthDriverResolver $drivers): void
    {
        if (!$this->container->has(ClockInterface::class)) {
            $this->container->bind(ClockInterface::class, new SystemClock(), LifetimeEnum::Singleton);
        }
        $this->container->bind(AuthDriverResolver::class, $drivers, LifetimeEnum::Singleton);

        $container = $this->container;
        $this->container->factory(AuthIdGeneratorInterface::class, static function () use ($container): AuthIdGeneratorInterface {
            $config = $container->get(ConfigRepository::class);
            if (!$config instanceof ConfigRepository) {
                throw new \RuntimeException('Auth ID configuration must resolve to ConfigRepository.');
            }

            return new UidAuthIdGenerator($config);
        })->singleton();

        if (!$this->container->has(PasswordPolicyInterface::class)) {
            $this->container->factory(PasswordPolicyInterface::class, static function () use ($container): PasswordPolicyInterface {
                $config = $container->get(ConfigRepository::class);
                if (!$config instanceof ConfigRepository) {
                    throw new \RuntimeException('Password policy configuration must resolve to ConfigRepository.');
                }

                return new BaselinePasswordPolicy(
                    minimumLength: $config->getInt('auth.password_policy.min_length', 12) ?? 12,
                    maximumLength: $config->getInt('auth.password_policy.max_length', 1024) ?? 1024,
                );
            })->singleton();
        }
    }
}
