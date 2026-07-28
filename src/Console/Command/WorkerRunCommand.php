<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Console\Input\Argument;
use Infocyph\Foundation\Console\Support\WorkerManager;

final class WorkerRunCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly WorkerManager $workers) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('worker:run')
            ->description('Run a dynamically scaling application worker supervisor.')
            ->argument(Argument::required('worker')->description(
                'Worker name declared in routes/workers.php, for example: emails.',
            ));
    }

    protected function handle(): int
    {
        try {
            $summary = $this->workers->run($this->arguments()->string('worker'));
        } catch (\Throwable $exception) {
            $this->io()->error('worker:run failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }
        if ($summary === null) {
            $this->io()->note('The worker is already supervised; this execution was skipped.');

            return ExitCode::SUCCESS;
        }
        $this->io()->details([
            'started' => $summary->started,
            'completed' => $summary->completed,
            'failed' => $summary->failed,
            'forced' => $summary->forced,
            'duration_seconds' => round($summary->durationSeconds, 3),
        ]);

        return $summary->successful() ? ExitCode::SUCCESS : ExitCode::FAILURE;
    }
}
