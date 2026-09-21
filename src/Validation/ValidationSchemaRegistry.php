<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Validation;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\ReqShield\Validator;

/**
 * Immutable Foundation-owned snapshot of named application validation schemas.
 *
 * Schema composition happens once while the application graph is built. Runtime
 * validation never mutates process-wide Foundation schema topology.
 */
final readonly class ValidationSchemaRegistry
{
    /** @var array<string, array<string, mixed>> */
    private array $schemas;

    /** @param array<string, array<string, mixed>> $baseSchemas */
    public function __construct(ConfigRepository $config, array $baseSchemas = [])
    {
        $schemas = $this->normalizeSchemas($baseSchemas);

        foreach ($this->normalizeSchemas($config->get('validation.schemas', [])) as $name => $schema) {
            $schemas[$name] = $schema;
        }

        foreach ($this->normalizeSchemas($config->get('validation.extend', [])) as $name => $rules) {
            $schemas[$name] = $this->normalizeSchema(Validator::composeSchemas(
                $schemas[$name] ?? [],
                $rules,
            ));
        }

        $this->schemas = $schemas;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->schemas;
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

    /**
     * @param array<int|string, mixed> $schema
     * @return array<string, mixed>
     */
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
