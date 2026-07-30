<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Option;
use Infocyph\Foundation\Application\Application;

final class SessionPruneCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('session:prune')
            ->description('Delete expired browser sessions outside the request path.')
            ->option(Option::value('limit')->description('Maximum rows/files to delete; default: 1000.'))
            ->option(self::jsonOption());
    }

    protected function handle(): int
    {
        $limit = $this->options()->nullableInt('limit') ?? 1_000;
        if ($limit <= 0) {
            $this->io()->error('session:prune --limit must be a positive integer.');

            return ExitCode::INVALID_USAGE;
        }

        try {
            $pruned = $this->application->session()->prune($limit);
        } catch (\Throwable $exception) {
            $this->io()->error('session:prune failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        $this->report(['pruned' => $pruned, 'limit' => $limit]);

        return ExitCode::SUCCESS;
    }
}
