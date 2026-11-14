<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

/** Mutable observation shared across scheduled callbacks and the test driver. */
final class ExecutionFlag
{
    public function __construct(private bool $value = false) {}

    public function set(): void
    {
        $this->value = true;
    }

    public function value(): bool
    {
        return $this->value;
    }
}
