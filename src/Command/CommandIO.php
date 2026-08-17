<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

interface CommandIO
{
    public function write(string $message): void;

    public function writeln(string $message = ''): void;

    public function error(string $message): void;
}
