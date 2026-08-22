<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

interface CommandHandlerInterface
{
    public static function define(CommandDefinition $command): void;

    public function run(CommandContext $context): int;
}
