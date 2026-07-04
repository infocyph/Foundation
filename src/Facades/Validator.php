<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\Foundation\Validation\ValidationManager;
use Infocyph\ReqShield\Support\ValidationResult;

final class Validator extends Facade
{
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
    public static function extend(string $schema, array $rules): void
    {
        self::manager()->extend($schema, $rules);
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
     * @param array<string, mixed> $payload
     */
    public static function validate(string $schema, array $payload): ValidationResult
    {
        return self::manager()->validate($schema, $payload);
    }
}
