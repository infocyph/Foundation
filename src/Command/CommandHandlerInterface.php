<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

interface CommandHandlerInterface
{
    public function execute(ParsedInput $input, CommandIO $io): int;
}
