<?php

declare(strict_types=1);

namespace App\Command\Reports;

use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandHandlerInterface;

final class DailyCommand implements CommandHandlerInterface
{
    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('reports:daily')
            ->description('Prepare a daily report')
            ->group('Reports');
    }

    public function run(CommandContext $context): int
    {
        $context->io()->success('Daily report command completed.');

        return 0;
    }
}
