<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class TerminalIO implements CommandIO
{
    public function error(string $message): void
    {
        fwrite(STDERR, $message . PHP_EOL);
    }

    public function write(string $message): void
    {
        fwrite(STDOUT, $message);
    }

    public function writeln(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }
}
