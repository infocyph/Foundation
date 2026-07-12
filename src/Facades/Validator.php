<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Validation\ValidationManager;
use Infocyph\ReqShield\CompiledValidator;
use Infocyph\ReqShield\Support\ValidationResult;
use Infocyph\ReqShield\Validator as ReqShieldValidator;

final class Validator extends Facade
{
    public static function compile(string $schema): CompiledValidator
    {
        return self::manager()->compile($schema);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function define(string $schema, array $rules): void
    {
        self::manager()->define($schema, $rules);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function defineFragment(string $name, array $rules): void
    {
        self::manager()->defineFragment($name, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public static function exportSchema(string $schema, string $format = 'json_schema'): array
    {
        return self::manager()->exportSchema($schema, $format);
    }

    /**
     * @param array<string, mixed> $rules
     */
    public static function extend(string $schema, array $rules): void
    {
        self::manager()->extend($schema, $rules);
    }

    /**
     * @return array<string, mixed>
     */
    public static function fragment(string $name, string $prefix = ''): array
    {
        return self::manager()->fragment($name, $prefix);
    }

    public static function hasFragment(string $name): bool
    {
        return self::manager()->hasFragment($name);
    }

    public static function hasSchema(string $name): bool
    {
        return self::manager()->hasSchema($name);
    }

    public static function manager(): ValidationManager
    {
        return self::app()->validator();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function schemas(): array
    {
        return self::manager()->schemas();
    }

    /**
     * @return array<string, mixed>
     */
    public static function schemaStats(string $schema): array
    {
        return self::manager()->schemaStats($schema);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function validate(string $schema, array $payload): ValidationResult
    {
        return self::manager()->validate($schema, $payload);
    }

    public static function validateRequest(string $schema, object $request): ValidationResult
    {
        return self::manager()->validateRequest($schema, $request);
    }

    public static function validator(string $schema): ReqShieldValidator
    {
        return self::manager()->validator($schema);
    }
}
