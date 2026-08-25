<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class SchedulerRuntime
{
    public function __construct(private Application $application)
    {
        if ($application->runtimeMode() !== RuntimeMode::Scheduler) {
            throw new \LogicException('SchedulerRuntime requires a scheduler Foundation application.');
        }
    }

    /**
     * Execute one due schedule unit with fresh scoped state.
     *
     * @template T
     * @param callable(ExecutionId):T $handler
     * @param array<string, mixed> $context
     * @return T
     */
    public function execute(
        callable $handler,
        array $context = [],
        ?ExecutionId $executionId = null,
    ): mixed {
        return $this->application->execution()->run($handler, $context, $executionId);
    }
}
