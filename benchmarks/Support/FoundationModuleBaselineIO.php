<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Benchmarks\Support;

use Infocyph\Foundation\Command\CommandIO;
use LogicException;

final class FoundationModuleBaselineIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    public mixed $payload = null;

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Module baseline does not accept interactive choices.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
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
        $this->payload = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        throw new LogicException('Module baseline does not request secrets.');
    }

    public function quiet(): bool
    {
        return true;
    }

    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Module baseline does not accept interactive text.');
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
