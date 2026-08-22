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

    /** @return list<string> */
    final protected function arguments(): array
    {
        return $this->input()->arguments();
    }

    /** @param list<string> $choices */
    final protected function choice(string $question, array $choices, ?string $default = null): string
    {
        return $this->io()->choice($question, $choices, $default);
    }

    final protected function confirm(string $question, bool $default = false): bool
    {
        return $this->io()->confirm($question, $default);
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

    final protected function info(string $message): void
    {
        $this->io()->info($message);
    }

    final protected function input(): ParsedInput
    {
        return $this->context()->input();
    }

    final protected function io(): CommandIO
    {
        return $this->context()->io();
    }

    final protected function note(string $message): void
    {
        $this->io()->note($message);
    }

    final protected function option(string $name, ?string $default = null): ?string
    {
        return $this->input()->option($name, $default);
    }

    final protected function password(string $question): string
    {
        return $this->io()->password($question);
    }

    final protected function read(string $question, ?string $default = null): string
    {
        return $this->io()->read($question, $default);
    }

    final protected function success(string $message): void
    {
        $this->io()->success($message);
    }

    /** @param list<string> $headers @param list<list<scalar|null>> $rows */
    final protected function table(array $headers, array $rows): void
    {
        $this->io()->table($headers, $rows);
    }

    /** @return list<string> */
    final protected function values(string $name): array
    {
        return $this->input()->values($name);
    }

    final protected function warning(string $message): void
    {
        $this->io()->warning($message);
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
