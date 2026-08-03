<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Command;

use Infocyph\Console\Command\CommandDefinition;
use Infocyph\Console\Command\ExitCode;
use Infocyph\Foundation\Console\Support\StorageLinkManager;

final class StorageLinkCommand extends AbstractFoundationCommand
{
    public function __construct(private readonly StorageLinkManager $links) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('storage:link')->description('Create configured public storage symbolic links safely.');
    }

    protected function handle(): int
    {
        try {
            $links = $this->links->create();
        } catch (\Throwable $exception) {
            $this->io()->error('storage:link failed: ' . $exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        foreach ($links as $link) {
            $message = sprintf('%s -> %s', $link['link'], $link['target']);
            $link['created'] ? $this->io()->success($message) : $this->io()->info('Already linked: ' . $message);
        }

        return ExitCode::SUCCESS;
    }
}
