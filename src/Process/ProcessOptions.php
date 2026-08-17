<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Process;

final readonly class ProcessOptions
{
    /** @param array<string, string> $environment */
    public function __construct(
        public ?string $cwd = null,
        public array $environment = [],
        public ?float $timeoutSeconds = null,
        public ?string $input = null,
        public bool $interactive = false,
    ) {
        if ($timeoutSeconds !== null && $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Process timeout must be positive.');
        }
    }
}
