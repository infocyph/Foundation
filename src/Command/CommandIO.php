<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

interface CommandIO
{
    /** @param list<string> $choices */
    public function choice(string $question, array $choices, ?string $default = null): string;

    public function confirm(string $question, bool $default = false): bool;

    public function error(string $message): void;

    public function info(string $message): void;

    public function interactive(): bool;

    public function json(mixed $value): void;

    public function machineReadable(): bool;

    public function note(string $message): void;

    public function password(string $question): string;

    public function quiet(): bool;

    public function read(string $question, ?string $default = null): string;

    public function success(string $message): void;

    /**
     * @param list<string> $headers
     * @param list<list<scalar|null>> $rows
     */
    public function table(array $headers, array $rows): void;

    public function warning(string $message): void;

    public function write(string $message): void;

    public function writeln(string $message = ''): void;
}
