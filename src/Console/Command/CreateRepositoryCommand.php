<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Input\Option;

final class CreateRepositoryCommand extends AbstractCreateCommand
{
    public static function define(CommandDefinition $command): void
    {
        self::configure($command, 'create:repository', 'Create a DBLayer-backed repository.', 'User');
        $command->option(Option::value('table')->description(
            'Backing table identifier; defaults to the plural snake_case repository name, for example: users.',
        ));
    }

    protected function artifact(): string
    {
        return 'repository';
    }

    protected function commandName(): string
    {
        return 'create:repository';
    }

    protected function table(): ?string
    {
        return $this->options()->nullableString('table');
    }
}
