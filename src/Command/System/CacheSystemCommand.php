<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command\System;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Command\ExitCode;

final class CacheSystemCommand extends SystemCommand
{
    public function __construct(private readonly Application $application) {}

    protected function handle(): int
    {
        return match ($this->canonicalName()) {
            'cache:forget' => $this->forget(),
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
}
