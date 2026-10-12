<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

/**
 * Per-application, development-only record of process-bound service resolution.
 * InterMix owns service state; Foundation records only pre-fork safety facts.
 */
final class ForkSafetyTracker
{
    /** @var array<string, true> */
    private array $resolved = [];

    public function firstResolvedService(): ?string
    {
        return array_key_first($this->resolved);
    }

    public function mark(string $id): void
    {
        $this->resolved[$id] = true;
    }
}
