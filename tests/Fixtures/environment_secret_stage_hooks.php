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

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Tests\Fixtures\EnvironmentSecretStageObserver;

function chmod(string $filename, int $permissions): bool
{
    if (EnvironmentSecretStageObserver::$active && EnvironmentSecretStageObserver::$failChmod) {
        return false;
    }

    return \chmod($filename, $permissions);
}

/** @throws \RuntimeException */
function file_put_contents(string $filename, string $data, int $flags = 0): int|false
{
    $written = \file_put_contents($filename, $data, $flags);
    if (EnvironmentSecretStageObserver::$active && $written !== false) {
        $stream = \fopen($filename, 'r');
        if (!is_resource($stream)) {
            throw new \RuntimeException('Unable to inspect release configuration staging.');
        }

        try {
            EnvironmentSecretStageObserver::inspect($stream);
        } finally {
            fclose($stream);
        }
    }

    return $written;
}

/** @throws \RuntimeException */
function tempnam(string $directory, string $prefix): string|false
{
    if (EnvironmentSecretStageObserver::$active && $prefix === '.foundation-config-') {
        $mode = fileperms($directory);
        if ($mode === false) {
            throw new \RuntimeException('Unable to inspect release staging directory permissions.');
        }
        EnvironmentSecretStageObserver::$configDirectoryMode = $mode & 0777;
    }

    return \tempnam(
        EnvironmentSecretStageObserver::$active
            ? (EnvironmentSecretStageObserver::$temporaryDirectory ?? $directory)
            : $directory,
        $prefix,
    );
}
