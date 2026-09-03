<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\InterMix\Exceptions\ContainerException;

final class FoundationExecutionScopeProbe {}

final class FoundationExecutionScopeProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        $builder->bindFactory(
            FoundationExecutionScopeProbe::class,
            static fn(): FoundationExecutionScopeProbe => new FoundationExecutionScopeProbe(),
            LifetimeEnum::Scoped,
        );
    }
}

final class FoundationFailingScopeLeaveProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if ($context->runtimeMode !== RuntimeMode::Cli) {
            return;
        }

        $builder->onScopeLeave(
            RuntimeMode::Cli->scopeName(),
            static function (string $scope, Container $container): never {
                unset($scope, $container);

                throw new RuntimeException('scope leave failure');
            },
        );
    }
}

it('isolates same-label CLI scopes across interleaved Fibers and sequential executions', function (): void {
    $app = Foundation::cli([
        'app' => ['env' => 'testing'],
        'providers' => ['common' => [FoundationExecutionScopeProvider::class]],
    ])->boot();

    $fiber = static function (string $id) use ($app): Fiber {
        return new Fiber(static function () use ($app, $id): array {
            return $app->execution()->run(
                static function (ExecutionId $executionId) use ($app): array {
                    $first = $app->make(FoundationExecutionScopeProbe::class);
                    $before = [
                        'id' => (string) $executionId,
                        'resolved_id' => (string) $app->make(ExecutionId::class),
                        'probe' => spl_object_id($first),
                    ];

                    Fiber::suspend($before);

                    $second = $app->make(FoundationExecutionScopeProbe::class);

                    return [
                        'id' => (string) $app->make(ExecutionId::class),
                        'probe' => spl_object_id($second),
                        'same_probe' => $first === $second,
                    ];
                },
                executionId: new ExecutionId($id),
            );
        });
    };

    $first = $fiber('fiber-one');
    $second = $fiber('fiber-two');
    $firstBefore = $first->start();
    $secondBefore = $second->start();

    expect($firstBefore)->toMatchArray(['id' => 'fiber-one', 'resolved_id' => 'fiber-one'])
        ->and($secondBefore)->toMatchArray(['id' => 'fiber-two', 'resolved_id' => 'fiber-two'])
        ->and($firstBefore['probe'])->not->toBe($secondBefore['probe']);

    $first->resume();
    $second->resume();
    $firstAfter = $first->getReturn();
    $secondAfter = $second->getReturn();

    expect($firstAfter)->toMatchArray(['id' => 'fiber-one', 'same_probe' => true])
        ->and($secondAfter)->toMatchArray(['id' => 'fiber-two', 'same_probe' => true])
        ->and($firstAfter['probe'])->toBe($firstBefore['probe'])
        ->and($secondAfter['probe'])->toBe($secondBefore['probe']);

    $sequentialFirst = $app->execution()->run(
        static fn(ExecutionId $executionId): array => [
            (string) $executionId,
            spl_object_id($app->make(FoundationExecutionScopeProbe::class)),
        ],
        executionId: new ExecutionId('sequential-one'),
    );
    $sequentialSecond = $app->execution()->run(
        static fn(ExecutionId $executionId): array => [
            (string) $executionId,
            spl_object_id($app->make(FoundationExecutionScopeProbe::class)),
        ],
        executionId: new ExecutionId('sequential-two'),
    );

    expect($sequentialFirst[0])->toBe('sequential-one')
        ->and($sequentialSecond[0])->toBe('sequential-two')
        ->and($sequentialFirst[1])->not->toBe($sequentialSecond[1]);

    expect(fn() => $app->execution()->run(
        static fn(): mixed => $app->execution()->run(
            static fn(): null => null,
            executionId: new ExecutionId('nested-inner'),
        ),
        executionId: new ExecutionId('nested-outer'),
    ))->toThrow(ContainerException::class, 'already active')
        ->and(fn() => $app->make(ExecutionId::class))->toThrow(ContainerException::class);
});

it('cleans a suspended Fiber execution when it is aborted by an injected exception', function (): void {
    $app = Foundation::cli([
        'app' => ['env' => 'testing'],
        'providers' => ['common' => [FoundationExecutionScopeProvider::class]],
    ])->boot();

    $fiber = new Fiber(static function () use ($app): never {
        $app->execution()->run(
            static function () use ($app): never {
                $app->make(FoundationExecutionScopeProbe::class);
                Fiber::suspend();

                throw new LogicException('unreachable');
            },
            executionId: new ExecutionId('aborted-fiber'),
        );

        throw new LogicException('unreachable');
    });

    $fiber->start();

    expect(fn() => $fiber->throw(new RuntimeException('fiber aborted')))
        ->toThrow(RuntimeException::class, 'fiber aborted');

    $after = $app->execution()->run(
        static fn(ExecutionId $executionId): string => (string) $executionId,
        executionId: new ExecutionId('after-abort'),
    );

    expect($after)->toBe('after-abort')
        ->and(fn() => $app->make(ExecutionId::class))->toThrow(ContainerException::class);
});

it('keeps the primary execution exception when explicit or scope-leave cleanup also fails', function (): void {
    $app = Foundation::cli([
        'app' => ['env' => 'testing'],
        'providers' => ['common' => [FoundationFailingScopeLeaveProvider::class]],
    ])->boot();

    expect(fn() => $app->execution()->run(
        static fn(): never => throw new DomainException('primary execution failure'),
    ))->toThrow(DomainException::class, 'primary execution failure');
});
