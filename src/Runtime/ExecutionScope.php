<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\InterMix\DI\Container;

final readonly class ExecutionScope
{
    public function __construct(
        private Container $container,
        private RuntimeContextTracker $externalState,
        private RuntimeMode $runtime,
    ) {}

    /**
     * Run one request, command, job, or scheduled execution in an isolated InterMix scope.
     * Ready contextual values are seeded directly; reusable services stay lazy singletons.
     *
     * The primary application failure always wins over cleanup failures. Cleanup
     * still runs fully; when the callback succeeds, the first cleanup failure is
     * surfaced to the caller.
     *
     * @template T
     * @param callable(ExecutionId):T $callback
     * @param array<string, mixed> $seeds
     * @return T
     */
    public function run(callable $callback, array $seeds = [], ?ExecutionId $executionId = null): mixed
    {
        $executionId ??= ExecutionId::generate();
        $seeds[ExecutionId::class] = $executionId;
        $seeds[RuntimeMode::class] ??= $this->runtime;
        $scope = $this->runtime->value . ':' . $executionId->value;

        $this->container->enterScope($scope, $seeds);

        $result = null;
        $primaryFailure = null;
        try {
            $result = $callback($executionId);
        } catch (\Throwable $exception) {
            $primaryFailure = $exception;
        }

        $cleanupFailure = null;
        try {
            $this->externalState->reset();
        } catch (\Throwable $exception) {
            $cleanupFailure = $exception;
        }

        try {
            $this->container->leaveScope();
        } catch (\Throwable $exception) {
            $cleanupFailure ??= $exception;
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }

        return $result;
    }
}
