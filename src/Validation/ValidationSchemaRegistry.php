<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\ReqShield\Validator;

/**
 * Foundation-owned registry for named application validation schemas.
 */
final class ValidationSchemaRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas = [];

    /** @param array<string, array<string, mixed>> $baseSchemas */
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

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->schemas;
    }

    /** @param array<string, mixed> $schema */
    public function define(string $name, array $schema): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Validation schema names must be non-empty strings.');
        }

        $this->schemas[$name] = $this->normalizeSchema($schema);
    }

    /** @param array<string, mixed> $rules */
    public function extend(string $name, array $rules): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Validation schema names must be non-empty strings.');
        }

        $composed = Validator::composeSchemas(
            $this->schemas[$name] ?? [],
            $this->normalizeSchema($rules),
        );
        $this->schemas[$name] = $this->normalizeSchema($composed);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->schemas);
    }

    /** @return array<string, mixed>|null */
    public function schema(string $name): ?array
    {
        return $this->schemas[$name] ?? null;
    }

    /** @return array<string, array<string, mixed>> */
    private function configuredExtensions(): array
    {
        return $this->normalizeSchemas($this->config->get('validation.extend', []));
    }

    /** @return array<string, array<string, mixed>> */
    private function configuredSchemas(): array
    {
        return $this->normalizeSchemas($this->config->get('validation.schemas', []));
    }

    /** @param array<mixed, mixed> $schema @return array<string, mixed> */
    private function normalizeSchema(array $schema): array
    {
        $normalized = [];
        foreach ($schema as $field => $rule) {
            if (is_string($field) && $field !== '') {
                $normalized[$field] = $rule;
            }
        }

        return $normalized;
    }

    /** @return array<string, array<string, mixed>> */
    private function normalizeSchemas(mixed $schemas): array
    {
        if (!is_array($schemas)) {
            return [];
        }

        $normalized = [];
        foreach ($schemas as $name => $schema) {
            if (is_string($name) && $name !== '' && is_array($schema)) {
                $normalized[$name] = $this->normalizeSchema($schema);
            }
        }

        return $normalized;
    }
}
