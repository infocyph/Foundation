<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\ScopeContext;
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

        self::assertRuntimeModeSeed($seeds);

        $executionId ??= ExecutionId::generate();
        $seeds[ExecutionId::class] = $executionId;

        $result = null;
        $resultSet = false;
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
                    &$resultSet,
                    &$primaryFailure,
                    &$cleanupFailure,
                ): void {
                    try {
                        $result = $callback($executionId);
                        $resultSet = true;
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
        if (!$resultSet) {
            throw new \LogicException('Execution scope completed without producing a callback result.');
        }

        /** @var T $result */
        return $result;
    }

    /**
     * Attach a host-owned, already-active InterMix execution scope.
     *
     * Runwire/Omnibus/host code owns the captured handle and its lifetime.
     * Foundation must neither start another scope nor clean up the borrowed
     * runtime state here; the original owner performs that exactly once.
     *
     * @template T
     * @param callable(ExecutionId):T $callback
     * @return T
     */
    public function withinBorrowedScope(ScopeContext $context, callable $callback): mixed
    {
        if ($this->runtime === RuntimeMode::Web) {
            throw new \LogicException('Web request scope is owned by Webrick and cannot be attached by Foundation.');
        }
        if (!$this->container instanceof RuntimeContainerInterface) {
            throw new \LogicException('Borrowed Foundation execution scopes require an InterMix runtime container.');
        }

        return $this->container->withinScopeContext(
            $context,
            function (RuntimeContainerInterface $runtime) use ($callback): mixed {
                $mode = $runtime->get(RuntimeMode::class);
                if ($mode !== $this->runtime) {
                    throw new \LogicException('Borrowed Foundation execution scope has a different runtime mode.');
                }
                $id = $runtime->get(ExecutionId::class);
                if (!$id instanceof ExecutionId) {
                    throw new \LogicException('Borrowed Foundation execution scope has no valid execution ID.');
                }

                return $callback($id);
            },
        );
    }

    /** @param array<string, mixed> $seeds */
    private static function assertRuntimeModeSeed(array $seeds): void
    {
        if (array_key_exists(RuntimeMode::class, $seeds)) {
            throw new \InvalidArgumentException('Execution scope cannot override its runtime mode.');
        }
    }

    /**
     * @param callable(ContainerInterface):mixed $callback
     * @param array<string, mixed> $seeds
     */
    private function withinScope(string $scope, callable $callback, array $seeds): mixed
    {
        if (!$this->container instanceof RuntimeContainerInterface) {
            throw new \LogicException('Foundation execution scopes require an InterMix runtime container.');
        }

        return $this->container->withinScope($scope, $callback, $seeds);
    }
}
