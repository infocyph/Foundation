<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Scheduling;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Runtime\ExecutionId;

/**
 * Public scheduler-scoped runtime execution service.
 *
 * @api
 */
final readonly class SchedulerRuntime
{
    /**
     * @throws \LogicException
     */
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
     * @throws \Infocyph\Foundation\Exception\ServiceResolutionException
     * @throws \LogicException
     * @throws \Throwable
     */
    public function execute(
        callable $handler,
        array $context = [],
        ?ExecutionId $executionId = null,
    ): mixed {
        return $this->application->execution()->run($handler, $context, $executionId);
    }
}
