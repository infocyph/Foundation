<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Fixtures;

use Infocyph\Foundation\Command\CommandIO;

final class NoopCommandIO implements CommandIO
{
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices);

        return $default ?? '';
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question);

        return $default;
    }

    public function error(string $message): void
    {
        unset($message);
    }

    public function info(string $message): void
    {
        unset($message);
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        unset($value);
    }

    public function machineReadable(): bool
    {
        return false;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        unset($question);

        return '';
    }

    public function quiet(): bool
    {
        return true;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question);

        return $default ?? '';
    }

    public function success(string $message): void
    {
        unset($message);
    }

    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    public function warning(string $message): void
    {
        unset($message);
    }

    public function write(string $message): void
    {
        unset($message);
    }

    public function writeln(string $message = ''): void
    {
        unset($message);
    }
}
