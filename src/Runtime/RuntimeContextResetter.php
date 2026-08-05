<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

/**
 * Clears only mutable capabilities touched by the current unit of work.
 */
final readonly class RuntimeContextResetter
{
    public function __construct(private RuntimeContextTracker $contexts) {}

    public function reset(): void
    {
        $this->contexts->reset();
    }
}
