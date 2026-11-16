<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\UID\Id;

final readonly class ExecutionId implements \Stringable
{
    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(public string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('Execution ID cannot be empty.');
        }
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function generate(): self
    {
        return new self(Id::ulid());
    }
}
