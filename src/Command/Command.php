<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

abstract class Command implements CommandHandlerInterface
{
    private ?CommandContext $context = null;

    abstract protected function handle(): int;

    final public function run(CommandContext $context): int
    {
        if ($this->context !== null) {
            throw new \LogicException('A command is already running.');
        }

        $this->context = $context;

        try {
            return $this->handle();
        } finally {
            $this->context = null;
        }
    }

    final protected function argument(int $index, ?string $default = null): ?string
    {
        return $this->input()->argument($index, $default);
    }

    final protected function context(): CommandContext
    {
        return $this->context ?? throw new \LogicException(
            'A command context is available only while the command is running.',
        );
    }

    final protected function error(string $message): void
    {
        $this->io()->error($message);
    }

    final protected function flag(string $name): bool
    {
        return $this->input()->flag($name);
    }

    final protected function input(): ParsedInput
    {
        return $this->context()->input();
    }

    final protected function io(): CommandIO
    {
        return $this->context()->io();
    }

    final protected function option(string $name, ?string $default = null): ?string
    {
        return $this->input()->option($name, $default);
    }

    final protected function write(string $message): void
    {
        $this->io()->write($message);
    }

    final protected function writeln(string $message = ''): void
    {
        $this->io()->writeln($message);
    }
}
