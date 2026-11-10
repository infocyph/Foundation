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

    #[\Override]
    public function choice(string $question, array $choices, ?string $default = null): string
    {
        throw new LogicException('Module baseline does not accept interactive choices.');
    }

    #[\Override]
    public function confirm(string $question, bool $default = false): bool
    {
        return false;
    }

    #[\Override]
    public function error(string $message): void
    {
        $this->errors[] = $message;
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
        $this->payload = $value;
    }

    #[\Override]
    public function machineReadable(): bool
    {
        return true;
    }

    #[\Override]
    public function note(string $message): void
    {
        unset($message);
    }

    #[\Override]
    public function password(string $question): string
    {
        throw new LogicException('Module baseline does not request secrets.');
    }

    #[\Override]
    public function quiet(): bool
    {
        return true;
    }

    #[\Override]
    public function read(string $question, ?string $default = null): string
    {
        throw new LogicException('Module baseline does not accept interactive text.');
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
