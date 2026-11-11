<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use RuntimeException;

final class EnvironmentSecretStageObserver
{
    public static bool $active = false;

    public static bool $failChmod = false;

    public static ?int $modeAtFirstWrite = null;

    public static int $writes = 0;

    /**
     * @throws \RuntimeException
     */
    public static function inspect(mixed $stream): void
    {
        if (!self::$active) {
            return;
        }

        $details = stream_get_meta_data($stream);
        if (!str_ends_with($details['uri'] ?? '', '.tmp')) {
            return;
        }

        $stat = fstat($stream);
        if (!is_array($stat)) {
            throw new RuntimeException('Unable to inspect environment stage at first write.');
        }
        self::$modeAtFirstWrite ??= $stat['mode'] & 0777;
        self::$writes++;
    }
}
