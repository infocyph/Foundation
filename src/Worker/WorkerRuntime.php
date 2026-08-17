<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Worker;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class WorkerRuntime
{
    public function __construct(private Application $application)
    {
        if ($application->runtimeMode() !== RuntimeMode::Worker) {
            throw new \LogicException('WorkerRuntime requires a worker Foundation application.');
        }
    }

    /**
     * Execute one job/message with fresh scoped state while singleton infrastructure remains hot.
     *
     * @template T
     * @param callable(ExecutionId):T $handler
     * @param array<string, mixed> $context
     * @return T
     */
    public function execute(callable $handler, array $context = []): mixed
    {
        return $this->application->execution()->run($handler, $context);
    }
}
