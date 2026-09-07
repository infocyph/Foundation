<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Foundation;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class FoundationPersistentRuntimeSoakProbe {}

final class FoundationPersistentRuntimeSoakProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);

        $builder->bindFactory(
            'persistent.runtime.soak.probe',
            static fn(): FoundationPersistentRuntimeSoakProbe => new FoundationPersistentRuntimeSoakProbe(),
            LifetimeEnum::Scoped,
        );
    }
}

it('releases scoped services across sustained success and failure execution pressure', function (): void {
    $app = Foundation::cli([
        'app' => ['env' => 'testing'],
        'providers' => [
            'common' => [FoundationPersistentRuntimeSoakProvider::class],
        ],
    ])->boot();

    $failures = 0;
    $iterations = 1_000;
    $batchSize = 100;

    for ($start = 0; $start < $iterations; $start += $batchSize) {
        /** @var list<WeakReference<FoundationPersistentRuntimeSoakProbe>> $references */
        $references = [];

        for ($offset = 0; $offset < $batchSize; $offset++) {
            $iteration = $start + $offset + 1;

            try {
                $app->execution()->run(function () use ($app, $iteration, &$references): void {
                    /** @var FoundationPersistentRuntimeSoakProbe $probe */
                    $probe = $app->make('persistent.runtime.soak.probe');
                    $references[] = WeakReference::create($probe);

                    if ($iteration % 17 === 0) {
                        throw new RuntimeException('deliberate soak execution failure');
                    }
                });
            } catch (RuntimeException $exception) {
                expect($exception->getMessage())->toBe('deliberate soak execution failure');
                ++$failures;
            }
        }

        gc_collect_cycles();

        foreach ($references as $reference) {
            expect($reference->get())->toBeNull();
        }
    }

    expect($failures)->toBe(intdiv($iterations, 17));
});
