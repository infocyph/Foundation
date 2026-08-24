<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Command\Command;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\ExitCode;

abstract class SystemCommand extends Command
{
    final public static function define(CommandDefinition $command): void
    {
        unset($command);

        throw new \LogicException('Foundation system command definitions are owned by CommandCatalog.');
    }

    final protected function canonicalName(): string
    {
        return $this->context()->definition()->commandName();
    }

    final protected function emit(mixed $data, ?string $message = null): int
    {
        if ($this->io()->machineReadable()) {
            $this->io()->json($data);
        } elseif ($message !== null) {
            $this->io()->writeln($message);
        } elseif (is_scalar($data) || $data === null) {
            $this->io()->writeln($data === null ? '' : (string) $data);
        } else {
            $this->io()->json($data);
        }

        return ExitCode::SUCCESS;
    }
}
