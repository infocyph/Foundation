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
/**
 * @throws \RuntimeException
 * @throws \ValueError
 */

function fwrite(mixed $stream, string $data, ?int $length = null): int|false
{
    if (!is_resource($stream)) {
        throw new \RuntimeException('Environment writes require an open stream.');
    }
    EnvironmentSecretStageObserver::inspect($stream);

    if ($length !== null && $length < 0) {
        throw new \ValueError('Write length must be non-negative.');
    }

    return $length === null ? \fwrite($stream, $data) : \fwrite($stream, $data, $length);
}
