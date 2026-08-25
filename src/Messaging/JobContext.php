<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Messaging;

final readonly class JobContext
{
    public function __construct(
        public string $queue,
        public int $attempt = 1,
        public bool $asynchronous = false,
    ) {
        if ($queue === '') {
            throw new \InvalidArgumentException('Job queue must not be empty.');
        }
        if ($attempt < 1) {
            throw new \InvalidArgumentException('Job attempt must be at least 1.');
        }
    }
}
