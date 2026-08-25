<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\ConfigurationException;

final readonly class EpicryptTokenPolicyResolver
{
    public function __construct(
        private Application $app,
    ) {}

    public function algorithm(): SymmetricJwtAlgorithm
    {
        $configured = $this->app->config()->get('security.jwt.algorithm', SymmetricJwtAlgorithm::HS256->value);
        if (!is_string($configured)) {
            throw new ConfigurationException('security.jwt.algorithm must be HS256, HS384, or HS512.');
        }

        return SymmetricJwtAlgorithm::tryFrom(strtoupper(trim($configured)))
            ?? throw new ConfigurationException('security.jwt.algorithm must be HS256, HS384, or HS512.');
    }

    public function audience(): string
    {
        return $this->requiredValue('audience');
    }

    public function issuer(): string
    {
        return $this->requiredValue('issuer');
    }

    public function leewaySeconds(): int
    {
        return $this->nonNegativeInt('leeway_seconds', 0);
    }

    public function maximumLifetimeSeconds(): int
    {
        return $this->positiveInt('maximum_lifetime_seconds', 1209600);
    }

    public function minimumKeyBytes(): int
    {
        return match ($this->algorithm()) {
            SymmetricJwtAlgorithm::HS256 => 32,
            SymmetricJwtAlgorithm::HS384 => 48,
            SymmetricJwtAlgorithm::HS512 => 64,
        };
    }

    private function nonNegativeInt(string $key, int $default): int
    {
        $value = $this->app->config()->get('security.jwt.' . $key, $default);
        if (is_int($value) && $value >= 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^(?:0|[1-9]\d*)$/D', $value) === 1) {
            return (int) $value;
        }

        throw new ConfigurationException(sprintf('security.jwt.%s must be a non-negative integer.', $key));
    }

    private function positiveInt(string $key, int $default): int
    {
        $value = $this->app->config()->get('security.jwt.' . $key, $default);
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && preg_match('/^[1-9]\d*$/D', $value) === 1) {
            return (int) $value;
        }

        throw new ConfigurationException(sprintf('security.jwt.%s must be a positive integer.', $key));
    }

    private function requiredValue(string $key): string
    {
        $value = $this->app->config()->get('security.jwt.' . $key);
        if (!is_string($value) || trim($value) === '') {
            throw new ConfigurationException(sprintf(
                'security.jwt.%s must be configured when auth.drivers.tokens is "security".',
                $key,
            ));
        }

        return trim($value);
    }
}
