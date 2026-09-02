<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Epicrypt\Password\PasswordHashOptions;

final class SecurityGraphFactory
{
    /** @param array<string, mixed> $config */
    public static function passwordOptions(array $config): PasswordHashOptions
    {
        $algorithm = $config['algorithm'] ?? PasswordHashAlgorithm::ARGON2ID->value;
        if (is_string($algorithm)) {
            $algorithm = PasswordHashAlgorithm::tryFrom(strtolower($algorithm))
                ?? throw new \InvalidArgumentException('Unsupported security.password.algorithm.');
        }
        if (!$algorithm instanceof PasswordHashAlgorithm) {
            throw new \InvalidArgumentException('security.password.algorithm must be a valid Epicrypt password algorithm.');
        }

        return new PasswordHashOptions(
            algorithm: $algorithm,
            memoryCost: self::positiveInt($config, 'memory_cost', PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            timeCost: self::positiveInt($config, 'time_cost', PASSWORD_ARGON2_DEFAULT_TIME_COST),
            threads: self::positiveInt($config, 'threads', PASSWORD_ARGON2_DEFAULT_THREADS),
            bcryptCost: self::positiveInt($config, 'cost', 12),
        );
    }

    /** @param array<string, mixed> $config */
    private static function positiveInt(array $config, string $key, int $default): int
    {
        if (!array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int) $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'security.password.%s must be a positive integer.',
            $key,
        ));
    }
}
