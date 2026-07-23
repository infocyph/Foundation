<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\Command;
use Infocyph\Console\Input\Option;
use Infocyph\Console\Input\ValueType;

abstract class AbstractFoundationCommand extends Command
{
    final protected static function jsonOption(): Option
    {
        return Option::value('json')
            ->type(ValueType::BOOLEAN)
            ->default(false)
            ->description('Emit the command report as JSON. Allowed values: true|false|1|0.');
    }

    /**
     * @param array<string, mixed> $report
     */
    final protected function report(array $report): void
    {
        $this->io()->text(json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
