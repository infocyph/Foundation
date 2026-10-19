<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext;
use Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration as CacheRunwireIntegration;

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\InterMix\DI\RuntimeContainerInterface;
use Infocyph\InterMix\DI\ScopeContext;
use Infocyph\Runwire\Coroutine\CoroutineScope;
use Infocyph\Runwire\Coroutine\TaskLocal;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\Runtime\Enum\RuntimeCapability;
use Infocyph\Runwire\RuntimeContext;
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
        $native = self::hostRunwireContext($seeds);

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
                $native,
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

                $native = $runtime->has(RunwireExecutionContext::class)
                    ? $runtime->get(RunwireExecutionContext::class)
                    : null;
                if ($native !== null && !$native instanceof RunwireExecutionContext) {
                    throw new \LogicException('Borrowed Foundation execution has invalid native Runwire context.');
                }

                return $this->withinRunwireContext($native, static fn(): mixed => $callback($id));
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
     * Verify that the host supplied the authoritative Runwire inputs before
     * creating a Foundation-owned worker/CLI/scheduler execution scope.
     * No runtime, request or coroutine scope is generated or owned here.
     *
     * @param array<string, mixed> $seeds
     */
    private static function hostRunwireContext(array $seeds): ?RunwireExecutionContext
    {
        if (
            !array_key_exists(RuntimeContext::class, $seeds)
            && !array_key_exists(RequestContext::class, $seeds)
            && !array_key_exists(CoroutineScope::class, $seeds)
        ) {
            $shared = CacheRunwireIntegration::current();
            if ($shared === null) {
                return null;
            }
            $seeds = array_filter([
                RuntimeContext::class => $shared->runtime,
                RequestContext::class => $shared->request,
                CoroutineScope::class => $shared->scope,
            ], static fn(mixed $value): bool => $value !== null);
        }

        $runtime = $seeds[RuntimeContext::class] ?? null;
        if (!$runtime instanceof RuntimeContext) {
            throw new \LogicException('Runwire execution inputs require the exact host-owned runtime context.');
        }
        $pid = getmypid();
        if (!is_int($pid) || $runtime->pid !== $pid) {
            throw new \LogicException('Runwire execution context belongs to a different process.');
        }

        $request = null;
        if (array_key_exists(RequestContext::class, $seeds)) {
            $request = $seeds[RequestContext::class];
            if (!$request instanceof RequestContext
                || $request->runtime() !== $runtime
                || $request->completed()
            ) {
                throw new \LogicException('Runwire execution request is completed or belongs to a different runtime.');
            }
            $request->cancellation->throwIfCancelled();
        }

        return new RunwireExecutionContext($runtime, $request, self::runwireScopeSeed($seeds, $runtime));
    }

    /**
     * @param array<string, mixed> $seeds
     */
    private static function runwireScopeSeed(array $seeds, RuntimeContext $runtime): ?CoroutineScope
    {
        if (!array_key_exists(CoroutineScope::class, $seeds)) {
            return null;
        }

        $scope = $seeds[CoroutineScope::class];
        if (!$scope instanceof CoroutineScope || !$runtime->supports(RuntimeCapability::RUNWIRE_COROUTINES)) {
            throw new \LogicException('Runwire execution coroutine scope requires a compatible host runtime.');
        }

        // A native read-only guard verifies the current task and live scope.
        $scope->hasLocal(new TaskLocal());
        $scope->cancellation()->throwIfCancelled();

        return $scope;
    }

    /** @param callable():mixed $callback */
    private function withinRunwireContext(?RunwireExecutionContext $context, callable $callback): mixed
    {
        if ($context === null) {
            return $callback();
        }

        return CacheRunwireIntegration::share(
            $context->request,
            $context->scope,
            $callback,
            $context->runtime,
        );
    }

    /**
     * @param callable(ContainerInterface):mixed $callback
     * @param array<string, mixed> $seeds
     */
    private function withinScope(
        string $scope,
        callable $callback,
        array $seeds,
        ?RunwireExecutionContext $native,
    ): mixed {
        if (!$this->container instanceof RuntimeContainerInterface) {
            throw new \LogicException('Foundation execution scopes require an InterMix runtime container.');
        }

        if ($this->container->has(RunwireExecutionContext::class)) {
            $seeds[RunwireExecutionContext::class] = $native;
        }
        if ($native !== null) {
            foreach ([
                RuntimeContext::class => $native->runtime,
                RequestContext::class => $native->request,
                CoroutineScope::class => $native->scope,
            ] as $id => $value) {
                if ($this->container->has($id)) {
                    $seeds[$id] = $value;
                }
            }
        }

        return $this->container->withinScope(
            $scope,
            fn(ContainerInterface $container): mixed => $this->withinRunwireContext(
                $native,
                static fn(): mixed => $callback($container),
            ),
            $seeds,
        );
    }
}
