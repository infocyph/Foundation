<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

final class ConfigRepository
{
    /**
     * @param array<string, mixed> $items
     */
    public function __construct(
        private array $items = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->items;
    }

    public function env(?string $default = null): ?string
    {
        $env = $this->get('app.env', $default);

        return is_string($env) && $env !== ''
            ? $env
            : $default;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === '') {
            return $this->items;
        }

        $segments = explode('.', $key);
        $current = $this->items;

        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return $default;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    public function has(string $key): bool
    {
        $sentinel = new \stdClass();

        return $this->get($key, $sentinel) !== $sentinel;
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

    /**
     * @param array<string, mixed> $values
     */
    public function merge(array $values): void
    {
        $this->items = ConfigMerger::merge($this->items, $values);
    }

    public function set(string $key, mixed $value): void
    {
        if ($key === '') {
            if (!is_array($value)) {
                return;
            }

            $this->items = $this->map($value);

            return;
        }

        $segments = explode('.', $key);
        $current = &$this->items;

        foreach ($segments as $segment) {
            if (!isset($current[$segment]) || !is_array($current[$segment])) {
                $current[$segment] = [];
            }

            $current = &$current[$segment];
        }

        $current = $value;
    }

    /**
     * @param array<mixed> $value
     * @return array<string, mixed>
     */
    private function map(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $item;
        }

        return $normalized;
    }
}
