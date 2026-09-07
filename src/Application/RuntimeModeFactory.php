<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

final class RuntimeModeFactory
{
    public static function from(string $value): RuntimeMode
    {
        return RuntimeMode::from($value);
    }
}
