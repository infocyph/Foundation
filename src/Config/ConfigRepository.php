<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Config;

final class ConfigRepository extends Config
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(array $items = [])
    {
        if ($items !== []) {
            $this->items = $items;
        }
    }

    public function env(?string $default = null): ?string
    {
        $env = $this->getString('app.env', $default);

        return is_string($env) && $env !== ''
            ? $env
            : $default;
    }

    public function isEnvironment(string $environment): bool
    {
        $current = $this->env();

        return $current !== null && strcasecmp($current, $environment) === 0;
    }

    public function isProduction(): bool
    {
        return $this->isEnvironment('production');
    }
}
