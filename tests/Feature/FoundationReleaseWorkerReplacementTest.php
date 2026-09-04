<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;

final class FoundationReleaseReplacementWorker implements WorkerProvider
{
    public static ?Closure $activateReplacement = null;

    public static bool $continuedAfterHeartbeat = false;

    public static int $runs = 0;

    public static bool $stopObserved = false;

    /** @var list<string> */
    public static array $executionIds = [];

    public function run(WorkerRuntime $runtime): int
    {
        self::$runs++;
        $runtime->execute(static function (ExecutionId $executionId): void {
            self::$executionIds[] = (string) $executionId;
        }, executionId: new ExecutionId('release-worker-a-item'));

        $activateReplacement = self::$activateReplacement
            ?? throw new RuntimeException('Replacement generation callback is unavailable.');
        $activateReplacement();
        self::$stopObserved = $runtime->stopRequested();
        $runtime->heartbeat();
        self::$continuedAfterHeartbeat = true;

        return 17;
    }
}

final class FoundationReleaseReplacementProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if ($context->runtimeMode !== RuntimeMode::Worker) {
            return;
        }

        $builder->singleton(
            FoundationReleaseReplacementWorker::class,
            FactoryDefinition::construct(FoundationReleaseReplacementWorker::class),
        );
    }
}

beforeEach(function (): void {
    FoundationReleaseReplacementWorker::$activateReplacement = null;
    FoundationReleaseReplacementWorker::$continuedAfterHeartbeat = false;
    FoundationReleaseReplacementWorker::$runs = 0;
    FoundationReleaseReplacementWorker::$stopObserved = false;
    FoundationReleaseReplacementWorker::$executionIds = [];
});

it('gracefully replaces a release worker when the active Foundation generation changes', function (): void {
    $project = foundationReleaseReplacementProject();
    $releaseRoot = $project . '/storage/releases';
    $config = foundationReleaseReplacementConfig($project);
    $compiler = new FoundationReleaseCompiler();

    try {
        $releaseA = $compiler->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: [
                'web' => [],
                'cli' => [],
                'worker' => [],
                'scheduler' => [],
            ],
            generation: 'release-worker-a',
        );
        $trustedA = hash_file('sha256', $releaseA['manifest']);
        expect($trustedA)->toBeString()->toMatch('/^[a-f0-9]{64}$/D');
        if (!is_string($trustedA)) {
            throw new RuntimeException('Unable to hash generation A Foundation manifest.');
        }

        $runtime = new FoundationReleaseRuntime()->nonWebPrevalidated(
            $config,
            RuntimeMode::Worker,
            $releaseRoot,
            $trustedA,
        );
        $loaded = $runtime->application->loadedReleaseGeneration();
        expect($loaded)->not->toBeNull()
            ->and($loaded?->generation)->toBe('release-worker-a')
            ->and($loaded?->releaseRoot)->toBe($releaseRoot)
            ->and($loaded?->trustedFoundationManifestSha256)->toBe($trustedA);

        FoundationReleaseReplacementWorker::$activateReplacement = static function () use (
            $compiler,
            $config,
            $releaseRoot,
        ): void {
            $compiler->buildAndActivate(
                $config,
                $releaseRoot,
                capabilities: [
                    'web' => [],
                    'cli' => [],
                    'worker' => [],
                    'scheduler' => [],
                ],
                generation: 'release-worker-b',
            );
        };

        expect((new WorkerManager($runtime->application))->run('generation-aware'))->toBe(0)
            ->and(FoundationReleaseReplacementWorker::$runs)->toBe(1)
            ->and(FoundationReleaseReplacementWorker::$executionIds)->toBe(['release-worker-a-item'])
            ->and(FoundationReleaseReplacementWorker::$stopObserved)->toBeTrue()
            ->and(FoundationReleaseReplacementWorker::$continuedAfterHeartbeat)->toBeFalse()
            ->and(new ActiveGeneration()->current($releaseRoot)['generation'])->toBe('release-worker-b')
            ->and($runtime->application->loadedReleaseGeneration()?->generation)->toBe('release-worker-a')
            ->and((new RuntimeProcessRegistry($runtime->application))->all('worker', 'generation-aware'))->toBe([]);
    } finally {
        FoundationReleaseReplacementWorker::$activateReplacement = null;
        foundationResetWebrickProductionRegistries();
        foundationReleaseReplacementRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationReleaseReplacementConfig(string $project): array
{
    return [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => [
                    'pre' => [],
                    'post' => [],
                ],
            ],
        ],
        'operations' => [
            'runtime_control' => [
                'driver' => 'file',
                'path' => $project . '/storage/framework/runtime-control.json',
            ],
            'runtime_registry' => [
                'path' => $project . '/storage/framework/runtime',
                'visibility' => 'host',
                'stale_seconds' => 30,
            ],
        ],
    ];
}

function foundationReleaseReplacementProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-release-worker-replacement-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap', 0777, true);
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/storage/framework', 0777, true);

    file_put_contents(
        $project . '/bootstrap/providers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['common' => [FoundationReleaseReplacementProvider::class]];\n",
    );
    file_put_contents(
        $project . '/routes/workers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['generation-aware' => FoundationReleaseReplacementWorker::class];\n",
    );
    file_put_contents($project . '/routes/web.php', "<?php\n\ndeclare(strict_types=1);\n");

    return $project;
}

function foundationReleaseReplacementRemove(string $directory): void
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
