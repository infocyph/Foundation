<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Token\Jwt\Enum\SymmetricJwtAlgorithm;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Exception\ConfigurationException;
use Infocyph\Foundation\Support\ValueNormalizer;

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
        $value = $this->app->config()->get('security.jwt.leeway_seconds', 0);

        return max(0, is_numeric($value) ? (int) $value : 0);
    }

    public function maximumLifetimeSeconds(): int
    {
        $value = $this->app->config()->get('security.jwt.maximum_lifetime_seconds', 1209600);

        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : 1209600;
    }

    private function requiredValue(string $key): string
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
