<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class CommandContext
{
    public function __construct(
        private ParsedInput $input,
        private CommandIO $io,
        private ExecutionId $executionId,
        private CommandDescriptor $descriptor,
    ) {}

    public function definition(): CommandDefinition
    {
        return $this->descriptor->definition;
    }

    public function descriptor(): CommandDescriptor
    {
        return $this->descriptor;
    }

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    public function input(): ParsedInput
    {
        return $this->input;
    }

    public function io(): CommandIO
    {
        return $this->io;
    }
}
