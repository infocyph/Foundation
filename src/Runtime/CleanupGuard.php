<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Throwable;

/** Runs best-effort cleanup without allowing cleanup failures to replace primary work failures. */
final class CleanupGuard
{
    public static function run(?Throwable $primaryFailure, callable ...$callbacks): void
    {
        $cleanupFailure = null;

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (Throwable $exception) {
                $cleanupFailure ??= $exception;
            }
        }

        if ($primaryFailure === null && $cleanupFailure !== null) {
            throw $cleanupFailure;
        }
    }
}
