<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;
use Psr\Container\ContainerInterface;

final readonly class ExecutionScope
{
    public function __construct(
        private ContainerInterface $container,
        private RuntimeMode $runtime,
    ) {}

    /**
     * Run one command, job, or scheduled execution in an isolated InterMix scope.
     * Web request scope ownership belongs to Webrick.
     *
     * The primary application failure always wins over cleanup failures. When
     * execution succeeds, explicit runtime cleanup wins over scope-leave cleanup.
     *
     * @template T
     * @param callable(ExecutionId):T $callback
     * @param array<string, mixed> $seeds
     * @return T
     */
    public function run(callable $callback, array $seeds = [], ?ExecutionId $executionId = null): mixed
    {
        if ($this->runtime === RuntimeMode::Web) {
            throw new \LogicException('Web request scope is owned by Webrick and cannot be entered by Foundation.');
        }

        $executionId ??= ExecutionId::generate();
        $seeds[ExecutionId::class] = $executionId;
        $seeds[RuntimeMode::class] ??= $this->runtime;

        $result = null;
        $primaryFailure = null;
        $cleanupFailure = null;
        $scopeFailure = null;

        try {
            $this->withinScope(
                $this->runtime->scopeName(),
                function (ContainerInterface $runtime) use (
                    $callback,
                    $executionId,
                    &$result,
                    &$primaryFailure,
                    &$cleanupFailure,
                ): void {
                    try {
                        $result = $callback($executionId);
                    } catch (\Throwable $exception) {
                        $primaryFailure = $exception;
                    }

                    try {
                        $state = $runtime->get(RuntimeExecutionState::class);
                        if (!$state instanceof RuntimeExecutionState) {
                            throw new \LogicException('RuntimeExecutionState binding is invalid.');
                        }
                        $state->cleanup();
                    } catch (\Throwable $exception) {
                        $cleanupFailure = $exception;
                    }
                },
                $seeds,
            );
        } catch (\Throwable $exception) {
            $scopeFailure = $exception;
        }

        if ($primaryFailure !== null) {
            throw $primaryFailure;
        }
        if ($cleanupFailure !== null) {
            throw $cleanupFailure;
        }
        if ($scopeFailure !== null) {
            throw $scopeFailure;
        }

        return $result;
    }

    /**
     * @param callable(ContainerInterface):mixed $callback
     * @param array<string, mixed> $seeds
     */
    private function withinScope(string $scope, callable $callback, array $seeds): mixed
    {
        return match (true) {
            $this->container instanceof Container => $this->container->withinScope($scope, $callback, $seeds),
            $this->container instanceof ProductionContainer => $this->container->withinScope($scope, $callback, $seeds),
            default => throw new \LogicException(
                'Foundation execution scopes require an InterMix development or generated production container.',
            ),
        };
    }
}
