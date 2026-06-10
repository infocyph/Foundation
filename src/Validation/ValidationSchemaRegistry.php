<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;

final class ValidationSchemaRegistry
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $schemas = [];

    /**
     * @param array<string, array<string, mixed>> $baseSchemas
     */
    public function __construct(
        private readonly ConfigRepository $config,
        array $baseSchemas = [],
    ) {
        foreach ($baseSchemas as $name => $schema) {
            $this->define($name, $schema);
        }

        foreach ($this->configuredSchemas() as $name => $schema) {
            $this->define($name, $schema);
        }

        foreach ($this->configuredExtensions() as $name => $rules) {
            $this->extend($name, $rules);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->schemas;
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function define(string $name, array $schema): void
    {
        if ($name === '') {
            return;
        }

        $this->schemas[$name] = $this->normalizeSchema($schema);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public function extend(string $name, array $rules): void
    {
        if ($name === '') {
            return;
        }

        $this->schemas[$name] = array_replace(
            $this->schemas[$name] ?? [],
            $this->normalizeSchema($rules),
        );
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->schemas);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function schema(string $name): ?array
    {
        $schema = $this->schemas[$name] ?? null;

        return is_array($schema) ? $schema : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredExtensions(): array
    {
        return $this->normalizeSchemas(
            $this->config->get('validation.extend', []),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredSchemas(): array
    {
        return $this->normalizeSchemas(
            $this->config->get('validation.schemas', []),
        );
    }

    /**
     * @param mixed $schemas
     * @return array<string, array<string, mixed>>
     */
    private function normalizeSchemas(mixed $schemas): array
    {
        if (!is_array($schemas)) {
            return [];
        }

        $normalized = [];
        foreach ($schemas as $name => $schema) {
            if (!is_string($name) || !is_array($schema)) {
                continue;
            }

            $normalized[$name] = $this->normalizeSchema($schema);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function normalizeSchema(array $schema): array
    {
        $normalized = [];
        foreach ($schema as $field => $rule) {
            if (!is_string($field)) {
                continue;
            }

            $normalized[$field] = $rule;
        }

        return $normalized;
    }
}
