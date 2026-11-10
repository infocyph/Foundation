<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Command\CommandIO;

final class NoopCommandIO implements CommandIO
{
    #[\Override]
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices);

        return $default ?? '';
    }

    #[\Override]
    public function confirm(string $question, bool $default = false): bool
    {
        unset($question);

        return $default;
    }

    #[\Override]
    public function error(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function info(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function interactive(): bool
    {
        return false;
    }

    #[\Override]
    public function json(mixed $value): void
    {
        unset($value);
    }

    #[\Override]
    public function machineReadable(): bool
    {
        return false;
    }

    #[\Override]
    public function note(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function password(string $question): string
    {
        unset($question);

        return '';
    }

    #[\Override]
    public function quiet(): bool
    {
        return true;
    }

    #[\Override]
    public function read(string $question, ?string $default = null): string
    {
        unset($question);

        return $default ?? '';
    }

    #[\Override]
    public function success(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    #[\Override]
    public function warning(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function write(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function writeln(string $message = ''): void
    {
        unset($message);
    }
}
