<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Epicrypt\Password\PasswordHashOptions;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class EpicryptConfigResolver
{
    public function __construct(
        private Application $app,
    ) {}

    public function passwordOptions(): PasswordHashOptions
    {
        $options = ValueNormalizer::associativeArray(
            $this->app->config()->get('security.password', []),
        );
        $algorithm = $options['algorithm'] ?? PasswordHashAlgorithm::ARGON2ID;
        if (is_string($algorithm)) {
            $algorithm = PasswordHashAlgorithm::tryFrom(strtolower($algorithm)) ?? PasswordHashAlgorithm::ARGON2ID;
        }
        if (!$algorithm instanceof PasswordHashAlgorithm) {
            $algorithm = PasswordHashAlgorithm::ARGON2ID;
        }

        return new PasswordHashOptions(
            algorithm: $algorithm,
            memoryCost: $this->positiveInt($options['memory_cost'] ?? null, PASSWORD_ARGON2_DEFAULT_MEMORY_COST),
            timeCost: $this->positiveInt($options['time_cost'] ?? null, PASSWORD_ARGON2_DEFAULT_TIME_COST),
            threads: $this->positiveInt($options['threads'] ?? null, PASSWORD_ARGON2_DEFAULT_THREADS),
            bcryptCost: $this->positiveInt($options['cost'] ?? null, 12),
        );
    }

    public function tokenAudience(): string
    {
        return $this->requiredTokenPolicyValue('audience');
    }

    public function tokenIssuer(): string
    {
        return $this->requiredTokenPolicyValue('issuer');
    }

    public function tokenLeeway(): int
    {
        $value = $this->app->config()->get('security.jwt.leeway_seconds', 0);

        return max(0, is_numeric($value) ? (int) $value : 0);
    }

    public function tokenMaximumLifetime(): int
    {
        return $this->positiveInt(
            $this->app->config()->get('security.jwt.maximum_lifetime_seconds', 1209600),
            1209600,
        );
    }

    private function positiveInt(mixed $value, int $default): int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : $default;
    }

    private function requiredTokenPolicyValue(string $key): string
    {
        $value = ValueNormalizer::nullableString($this->app->config()->get('security.jwt.' . $key));
        if ($value === null) {
            throw new ConfigurationException(sprintf(
                'security.jwt.%s must be configured when auth.drivers.tokens is "security".',
                $key,
            ));
        }

        return $value;
    }
}
