<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Cache\CacheSchemaManager;
use Infocyph\Foundation\Command\ExitCode;

final class CacheSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'cache:forget' => $this->forget(),
            'cache:schema:install' => $this->schemaInstall(),
            'cache:schema:status' => $this->schemaStatus(),
            default => throw new \LogicException('Unsupported cache system command.'),
        };
    }

    private function forget(): int
    {
        $key = $this->argument(0)
            ?? throw new \LogicException('Validated cache key is unavailable.');
        $removed = $this->application->make(CacheManager::class)
            ->store($this->option('store'))
            ->delete($key);

        if (!$removed) {
            $this->io()->error(sprintf('Cache backend could not forget key "%s".', $key));

            return ExitCode::FAILURE;
        }

        return $this->emit(
            ['key' => $key, 'store' => $this->option('store'), 'removed' => true],
            sprintf('Forgot cache key "%s".', $key),
        );
    }

    private function schemaInstall(): int
    {
        $schemas = new CacheSchemaManager($this->application);
        $schemas->install($this->option('connection'));

        return $this->schemaResponse($schemas->statuses($this->option('connection'), true));
    }

    /**
     * @param list<array{name:string,applicable:bool,installed:bool,state:string,detail:string}> $rows
     */
    private function schemaResponse(array $rows): int
    {
        $failed = array_any($rows, static fn(array $row): bool => $row['applicable'] && !$row['installed']);

        if ($this->io()->machineReadable()) {
            $this->io()->json(['schemas' => $rows]);
        } else {
            $this->io()->table(
                ['Cache resource', 'Applicable', 'Installed', 'State', 'Detail'],
                array_map(
                    static fn(array $row): array => [
                        $row['name'],
                        $row['applicable'],
                        $row['installed'],
                        $row['state'],
                        $row['detail'],
                    ],
                    $rows,
                ),
            );
        }

        return $failed ? ExitCode::FAILURE : ExitCode::SUCCESS;
    }

    private function schemaStatus(): int
    {
        return $this->schemaResponse(
            new CacheSchemaManager($this->application)->statuses($this->option('connection')),
        );
    }
}
