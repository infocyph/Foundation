<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Auth\Internal;

use Infocyph\Foundation\Application\Application;

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

        return is_array($options) ? $options : [];
    }

    public function tokenAudience(): ?string
    {
        return $this->optionalString($this->app->config()->get('auth.epicrypt.tokens.audience'));
    }

    public function tokenIssuer(): ?string
    {
        return $this->optionalString($this->app->config()->get('auth.epicrypt.tokens.issuer'));
    }

    public function tokenLeeway(): int
    {
        return max(0, (int) $this->app->config()->get('auth.epicrypt.tokens.leeway_seconds', 0));
    }

    private function optionalString(mixed $value): ?string
    {
        return is_string($value) && $value !== ''
            ? $value
            : null;
    }
}
