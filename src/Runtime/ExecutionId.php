<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

final readonly class ExecutionId implements \Stringable
{
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Execution ID cannot be empty.');
        }
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public static function generate(): self
    {
        return new self(bin2hex(random_bytes(16)));
    }
}
