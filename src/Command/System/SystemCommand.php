<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Command\Command;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\ExitCode;

abstract class SystemCommand extends Command
{
    /**
     * @throws \LogicException
     */
    #[\Override]
    final public static function define(CommandDefinition $command): void
    {
        unset($command);

        throw new \LogicException('Foundation system command definitions are owned by CommandCatalog.');
    }

    final protected function canonicalName(): string
    {
        return $this->context()->definition()->commandName();
    }

    /**
     * @psalm-param array<int<0, max>|string, mixed> $data
     */
    final protected function emit(array $data, ?string $message = null): int
    {
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } elseif ($message !== null) {
            $this->io()->writeln($message);
        } else {
            $this->io()->json($data);
        }

        return ExitCode::SUCCESS;
    }
}
