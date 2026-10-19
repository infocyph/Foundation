<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration;

/**
 * Read the native fiber-scoped host binding without creating or retaining one.
 * The Runwire/CacheLayer integration owns the request, scope and restoration.
 */
final readonly class NativeRunwireOperation
{
    public static function current(): ?RunwireExecutionContext
    {
        $context = RunwireIntegration::current();
        if ($context === null) {
            return null;
        }

        $request = $context->request;
        if ($request !== null) {
            if ($request->completed() || $request->runtime() !== $context->runtime) {
                throw new \LogicException('Native Runwire operation has stale host request ownership.');
            }

            $request->cancellation->throwIfCancelled();
        }

        $context->scope?->cancellation()->throwIfCancelled();

        return $context;
    }
}
