<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\RuntimeMode;

final readonly class CommandDefinition
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $name,
        public string $description,
        public string $group,
        public RuntimeMode $runtime = RuntimeMode::Cli,
        public array $capabilities = [],
    ) {
        if ($name === '' || preg_match('/^[a-z][a-z0-9:_-]*$/', $name) !== 1) {
            throw new \InvalidArgumentException(sprintf('Invalid command name "%s".', $name));
        }
    }
}
