<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Messaging\InterMixExecutionScope;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Omnibus\Envelope\Envelope;
use Infocyph\Omnibus\Envelope\MessageIdStamp;

final readonly class FoundationGeneratedRuntimeProbe
{
    public function __construct(public RuntimeMode $runtime) {}
}

final class FoundationGeneratedRuntimeScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationGeneratedRuntimeDynamicProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->bindFactory('foundation.generated.dynamic', static fn(): stdClass => new stdClass());
    }
}

final class FoundationGeneratedRuntimeProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->singleton(FoundationGeneratedRuntimeProbe::class, FactoryDefinition::construct(
            FoundationGeneratedRuntimeProbe::class,
            [new ServiceReference(RuntimeMode::class)],
        ));
        $builder->scoped(
            FoundationGeneratedRuntimeScopedProbe::class,
            FactoryDefinition::construct(FoundationGeneratedRuntimeScopedProbe::class),
        );
    }
}

it('compiles and reuses minimal generated CLI and scheduler runtimes', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $compiler = new GeneratedRuntimeCompiler();

    try {
        foreach ([RuntimeMode::Cli, RuntimeMode::Scheduler] as $mode) {
            $artifact = $project . '/bootstrap/cache/' . $mode->value . '.php';
            $report = $compiler->compile($config, $mode, $artifact);
            expect($report['runtime'])->toBe($mode->value)
                ->and($report['skipped'])->toBe([])
                ->and($report['capabilities'])->toBe([])
                ->and(is_file($artifact))->toBeTrue()
                ->and(is_file($artifact . '.meta.json'))->toBeTrue()
                ->and(is_file($artifact . '.foundation.json'))->toBeTrue();

            $runtime = GeneratedRuntime::load($config, $mode, $artifact);
            expect($runtime->application->runtimeMode())->toBe($mode)
                ->and($runtime->application->make(FoundationGeneratedRuntimeProbe::class)->runtime)->toBe($mode)
                ->and($runtime->container->has('foundation.http'))->toBeFalse()
                ->and($runtime->container->has('foundation.cache'))->toBeFalse()
                ->and($runtime->container->has('foundation.messaging'))->toBeFalse();

            $first = $runtime->application->execution()->run(
                static fn(ExecutionId $id): array => [
                    (string) $id,
                    $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                ],
                executionId: new ExecutionId($mode->value . '-one'),
            );
            $second = $runtime->application->execution()->run(
                static fn(ExecutionId $id): array => [
                    (string) $id,
                    $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                ],
                executionId: new ExecutionId($mode->value . '-two'),
            );

            expect($first[0])->toBe($mode->value . '-one')
                ->and($second[0])->toBe($mode->value . '-two')
                ->and($first[1])->not->toBe($second[1]);
        }
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('loads and scopes a trusted CLI production container without rebuilding the source graph', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/cli.php';

    try {
        $report = new GeneratedRuntimeCompiler()->compile($config, RuntimeMode::Cli, $artifact);
        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\nthrow new RuntimeException('generated runtime rebuilt source providers');\n",
        );

        $runtime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Cli,
            $artifact,
            $report['metadata_sha256'],
            $report['digest'],
        );

        expect($runtime->application->runtimeMode())->toBe(RuntimeMode::Cli)
            ->and($runtime->application->make(FoundationGeneratedRuntimeProbe::class)->runtime)
            ->toBe(RuntimeMode::Cli)
            ->and($runtime->container->has('foundation.http'))->toBeFalse();

        $first = $runtime->application->execution()->run(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
            ],
            executionId: new ExecutionId('trusted-cli-one'),
        );
        $second = $runtime->application->execution()->run(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
            ],
            executionId: new ExecutionId('trusted-cli-two'),
        );

        expect($first[0])->toBe('trusted-cli-one')
            ->and($second[0])->toBe('trusted-cli-two')
            ->and($first[1])->not->toBe($second[1]);
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('keeps one trusted worker container hot while isolating provider jobs and Omnibus messages', function (): void {
    if (!class_exists(\Infocyph\Omnibus\MessageBus::class)) {
        $this->markTestSkipped('Install the messaging module to run the generated worker integration test.');
    }

    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/worker.php';

    try {
        $report = new GeneratedRuntimeCompiler()->compile(
            $config,
            RuntimeMode::Worker,
            $artifact,
            ['messaging'],
        );
        expect($report['skipped'])->toBe([])
            ->and($report['capabilities'])->toBe(['messaging' => true]);

        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\nthrow new RuntimeException('generated worker rebuilt source providers');\n",
        );
        $runtime = GeneratedRuntime::loadPrevalidated(
            $config,
            RuntimeMode::Worker,
            $artifact,
            $report['metadata_sha256'],
            $report['digest'],
            ['messaging'],
        );
        expect($runtime->container->has('foundation.messaging'))->toBeTrue()
            ->and($runtime->container->has('foundation.http'))->toBeFalse()
            ->and($runtime->container->has('foundation.cache'))->toBeFalse();

        $containerId = spl_object_id($runtime->container);
        $worker = new WorkerRuntime($runtime->application);
        $seen = [];
        foreach (range(1, 128) as $index) {
            $job = 'job-' . $index;
            $seen[] = $worker->execute(
                static fn(ExecutionId $id): array => [
                    (string) $id,
                    $runtime->application->make('foundation.worker.job'),
                    $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
                ],
                ['foundation.worker.job' => $job],
                new ExecutionId('worker-' . $index),
            );
        }

        expect(spl_object_id($runtime->container))->toBe($containerId)
            ->and($seen[0][0])->toBe('worker-1')
            ->and($seen[0][1])->toBe('job-1')
            ->and($seen[127][0])->toBe('worker-128')
            ->and($seen[127][1])->toBe('job-128')
            ->and(array_unique(array_column($seen, 2)))->toHaveCount(128);

        $failedReference = null;
        try {
            $worker->execute(
                static function () use ($runtime, &$failedReference): never {
                    $probe = $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class);
                    $failedReference = WeakReference::create($probe);

                    throw new RuntimeException('generated worker item failure');
                },
                ['foundation.worker.job' => 'failed-job'],
                new ExecutionId('worker-failed'),
            );
        } catch (RuntimeException $exception) {
            expect($exception->getMessage())->toBe('generated worker item failure');
        }
        gc_collect_cycles();

        expect($failedReference)->toBeInstanceOf(WeakReference::class)
            ->and($failedReference?->get())->toBeNull()
            ->and(fn() => $runtime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class);

        $afterFailure = $worker->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $runtime->application->make('foundation.worker.job'),
                $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
            ],
            ['foundation.worker.job' => 'after-failure'],
            new ExecutionId('worker-after-failure'),
        );
        expect($afterFailure[0])->toBe('worker-after-failure')
            ->and($afterFailure[1])->toBe('after-failure')
            ->and(spl_object_id($runtime->container))->toBe($containerId);

        $messageScope = $runtime->application->make(InterMixExecutionScope::class);
        $messageOne = new stdClass();
        $envelopeOne = new Envelope($messageOne, [new MessageIdStamp('message-one')]);
        $firstMessage = $messageScope->run(
            $envelopeOne,
            static fn(object $message, Envelope $delivery): array => [
                (string) $runtime->application->make(ExecutionId::class),
                $message === $messageOne,
                $delivery === $envelopeOne,
                $runtime->application->make(Envelope::class) === $envelopeOne,
                $runtime->application->make('omnibus.message') === $messageOne,
                $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
            ],
        );

        $messageTwo = new stdClass();
        $envelopeTwo = new Envelope($messageTwo, [new MessageIdStamp('message-two')]);
        $secondMessage = $messageScope->run(
            $envelopeTwo,
            static fn(object $message, Envelope $delivery): array => [
                (string) $runtime->application->make(ExecutionId::class),
                $message === $messageTwo,
                $delivery === $envelopeTwo,
                $runtime->application->make(Envelope::class) === $envelopeTwo,
                $runtime->application->make('omnibus.message') === $messageTwo,
                $runtime->application->make(FoundationGeneratedRuntimeScopedProbe::class)->sequence,
            ],
        );

        expect($firstMessage)->toMatchArray([
            'omnibus:message-one',
            true,
            true,
            true,
            true,
        ])->and($secondMessage)->toMatchArray([
            'omnibus:message-two',
            true,
            true,
            true,
            true,
        ])->and($firstMessage[5])->not->toBe($secondMessage[5])
            ->and(spl_object_id($runtime->container))->toBe($containerId)
            ->and(fn() => $runtime->application->make(ExecutionId::class))
            ->toThrow(ServiceResolutionException::class);
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('keeps the last good artifact when a new build contains skipped definitions', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/cli.php';
    $compiler = new GeneratedRuntimeCompiler();

    try {
        $compiler->compile($config, RuntimeMode::Cli, $artifact);
        $before = [
            hash_file('sha256', $artifact),
            hash_file('sha256', $artifact . '.meta.json'),
            hash_file('sha256', $artifact . '.foundation.json'),
        ];

        $invalid = $config;
        $invalid['providers']['common'][] = FoundationGeneratedRuntimeDynamicProvider::class;
        expect(fn() => $compiler->compile($invalid, RuntimeMode::Cli, $artifact))
            ->toThrow(RuntimeException::class, 'not statically compiled');

        expect([
            hash_file('sha256', $artifact),
            hash_file('sha256', $artifact . '.meta.json'),
            hash_file('sha256', $artifact . '.foundation.json'),
        ])->toBe($before);
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

it('rejects stale runtime, configuration, and capability identities before loading an artifact', function (): void {
    $project = foundationGeneratedRuntimeProject();
    $config = foundationGeneratedRuntimeConfig($project);
    $artifact = $project . '/bootstrap/cache/cli.php';

    try {
        new GeneratedRuntimeCompiler()->compile($config, RuntimeMode::Cli, $artifact);

        expect(fn() => GeneratedRuntime::load($config, RuntimeMode::Worker, $artifact))
            ->toThrow(RuntimeException::class, 'identity does not match');

        $changed = $config;
        $changed['app']['name'] = 'Changed generated runtime';
        expect(fn() => GeneratedRuntime::load($changed, RuntimeMode::Cli, $artifact))
            ->toThrow(RuntimeException::class, 'identity does not match');

        expect(fn() => GeneratedRuntime::load($config, RuntimeMode::Cli, $artifact, ['cache']))
            ->toThrow(RuntimeException::class, 'identity does not match');
    } finally {
        foundationGeneratedRuntimeRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationGeneratedRuntimeConfig(string $project): array
{
    return [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'providers' => [
            'common' => [FoundationGeneratedRuntimeProvider::class],
        ],
    ];
}

function foundationGeneratedRuntimeProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-generated-runtime-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap/cache', 0777, true);

    return $project;
}

function foundationGeneratedRuntimeRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
