<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\Crypto\AeadCipher;
use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Epicrypt\Password\PasswordHasher;
use Infocyph\Epicrypt\Password\PasswordHashOptions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class SecurityServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        if (!class_exists(AeadCipher::class)) {
            throw new \LogicException(
                'Foundation security services require infocyph/epicrypt; run "php infbyte module:install crypto".',
            );
        }

        $container = $app->container();

        if (!$this->hasExplicitBinding($container, PasswordHashOptions::class)) {
            $this->bindFactory(
                $container,
                PasswordHashOptions::class,
                fn(): PasswordHashOptions => $this->passwordOptions($app),
                LifetimeEnum::Singleton,
            );
        }

        if (!$this->hasExplicitBinding($container, PasswordHasher::class)) {
            $this->bindFactory(
                $container,
                PasswordHasher::class,
                fn(): PasswordHasher => new PasswordHasher($app->make(PasswordHashOptions::class)),
                LifetimeEnum::Singleton,
            );
        }

        if (!$this->hasExplicitBinding($container, AeadCipher::class)) {
            $container->bind(AeadCipher::class, new AeadCipher(), LifetimeEnum::Singleton);
        }

        $this->bindFactory(
            $container,
            'foundation.security',
            fn() => $app->make(AeadCipher::class),
            LifetimeEnum::Singleton,
        );
        $this->bindFactory(
            $container,
            'foundation.crypto',
            fn() => $app->make(AeadCipher::class),
            LifetimeEnum::Singleton,
        );
    }

    private function passwordOptions(Application $app): PasswordHashOptions
    {
        $config = ValueNormalizer::associativeArray($app->config()->get('security.password', []));
        $algorithm = $config['algorithm'] ?? PasswordHashAlgorithm::ARGON2ID;
        if (is_string($algorithm)) {
            $algorithm = PasswordHashAlgorithm::tryFrom(strtolower($algorithm))
                ?? throw new \InvalidArgumentException('Unsupported security.password.algorithm.');
        }
        if (!$algorithm instanceof PasswordHashAlgorithm) {
            throw new \InvalidArgumentException('security.password.algorithm must be a valid Epicrypt password algorithm.');
        }

        return new PasswordHashOptions(
            algorithm: $algorithm,
            memoryCost: $this->positiveInt($config['memory_cost'] ?? null, PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            timeCost: $this->positiveInt($config['time_cost'] ?? null, PASSWORD_ARGON2_DEFAULT_TIME_COST),
            threads: $this->positiveInt($config['threads'] ?? null, PASSWORD_ARGON2_DEFAULT_THREADS),
            bcryptCost: $this->positiveInt($config['cost'] ?? null, 12),
        );
    }

    private function positiveInt(mixed $value, int $default): int
    {
        if (!is_numeric($value) || (int) $value < 1) {
            return $default;
        }

        return (int) $value;
    }
}
