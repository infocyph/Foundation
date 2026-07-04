<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

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
        $options = $this->app->config()->get('auth.epicrypt.password', []);

        return ValueNormalizer::associativeArray($options);
    }

    public function tokenAudience(): ?string
    {
        return ValueNormalizer::nullableString($this->app->config()->get('auth.epicrypt.tokens.audience'));
    }

    public function tokenIssuer(): ?string
    {
        return ValueNormalizer::nullableString($this->app->config()->get('auth.epicrypt.tokens.issuer'));
    }

    public function tokenLeeway(): int
    {
        $value = $this->app->config()->get('auth.epicrypt.tokens.leeway_seconds', 0);

        return max(0, is_numeric($value) ? (int) $value : 0);
    }
}
