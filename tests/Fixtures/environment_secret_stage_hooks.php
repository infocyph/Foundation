<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Foundation\Tests\Fixtures\EnvironmentSecretStageObserver;

function chmod(string $filename, int $permissions): bool
{
    if (EnvironmentSecretStageObserver::$failChmod && str_ends_with($filename, '.tmp')) {
        return false;
    }

    return \chmod($filename, $permissions);
}

function fwrite(mixed $stream, string $data, ?int $length = null): int|false
{
    EnvironmentSecretStageObserver::inspect($stream);

    return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
}
