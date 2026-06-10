<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Facades;

use Infocyph\ReqShield\Support\ValidationResult;

final class Validator extends Facade
{
    public static function define(string $schema, array $rules): void
    {
        static::manager()->define($schema, $rules);
    }

    public static function extend(string $schema, array $rules): void
    {
        static::manager()->extend($schema, $rules);
    }

    public static function hasSchema(string $name): bool
    {
        return static::manager()->hasSchema($name);
    }

    public static function manager(): \Infocyph\Foundation\Validation\ValidationManager
    {
        return static::app()->validator();
    }

    public static function schemas(): array
    {
        return static::manager()->schemas();
    }

    public static function validate(string $schema, array $payload): ValidationResult
    {
        return static::manager()->validate($schema, $payload);
    }
}
