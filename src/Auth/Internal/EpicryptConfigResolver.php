<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Epicrypt\Password\Enum\PasswordHashAlgorithm;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class EpicryptConfigResolver
{
    public function __construct(
        private Application $app,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function passwordOptions(): array
    {
        $options = ValueNormalizer::associativeArray(
            $this->app->config()->get('security.password', []),
        );

        if (is_string($options['algorithm'] ?? null)) {
            $options['algorithm'] = PasswordHashAlgorithm::from(strtolower($options['algorithm']));
        }

        return $options;
    }

    public function tokenAudience(): ?string
    {
        return ValueNormalizer::nullableString($this->app->config()->get('security.jwt.audience'));
    }

    public function tokenIssuer(): ?string
    {
        return ValueNormalizer::nullableString($this->app->config()->get('security.jwt.issuer'));
    }

    public function tokenLeeway(): int
    {
        $value = $this->app->config()->get('security.jwt.leeway_seconds', 0);

        return max(0, is_numeric($value) ? (int) $value : 0);
    }
}
