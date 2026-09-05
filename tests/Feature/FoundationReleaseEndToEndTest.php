<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseBootstrap;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\Foundation\Worker\WorkerManager;
use Infocyph\Foundation\Worker\WorkerProvider;
use Infocyph\Foundation\Worker\WorkerRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\InterMix\DI\Support\FactoryDefinition;
use Infocyph\InterMix\DI\Support\ServiceReference;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final readonly class FoundationPhase8ReleaseProbe
{
    public function __construct(public RuntimeMode $runtime) {}
}

final class FoundationPhase8ReleaseScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final readonly class FoundationPhase8ReleaseCliCommand implements CommandHandlerInterface
{
    public function __construct(private FoundationPhase8ReleaseProbe $probe) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('phase8:cli')->runtime(RuntimeMode::Cli);
    }

    public function run(CommandContext $context): int
    {
        $context->io()->info($this->probe->runtime->value);

        return ExitCode::SUCCESS;
    }
}

final readonly class FoundationPhase8ReleaseSchedulerCommand implements CommandHandlerInterface
{
    public function __construct(private FoundationPhase8ReleaseProbe $probe) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('phase8:scheduler')->runtime(RuntimeMode::Scheduler);
    }

    public function run(CommandContext $context): int
    {
        $context->io()->info($this->probe->runtime->value);

        return ExitCode::SUCCESS;
    }
}

final readonly class FoundationPhase8ReleaseWorkerProvider implements WorkerProvider
{
    public static ?RuntimeMode $observedRuntime = null;

    public function __construct(private FoundationPhase8ReleaseProbe $probe) {}

    public function run(WorkerRuntime $runtime): int
    {
        self::$observedRuntime = $this->probe->runtime;
        $runtime->execute(static fn(): null => null);

        return 0;
    }
}

final class FoundationPhase8ReleaseIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $lines = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in release runtime tests.');
    }

    public function confirm(string $question, bool $default = false): bool
    {
        unset($question, $default);

        return false;
    }

    public function error(string $message): void
    {
        $this->errors[] = $message;
    }

    public function info(string $message): void
    {
        $this->lines[] = $message;
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->lines[] = json_encode($value, JSON_THROW_ON_ERROR);
    }

    public function machineReadable(): bool
    {
        return false;
    }

    public function note(string $message): void
    {
        $this->lines[] = $message;
    }

    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in release runtime tests.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in release runtime tests.');
    }

    public function success(string $message): void
    {
        $this->lines[] = $message;
    }

    public function table(array $headers, array $rows): void
    {
        unset($headers, $rows);
    }

    public function warning(string $message): void
    {
        $this->lines[] = $message;
    }

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function writeln(string $message = ''): void
    {
        $this->lines[] = $message;
    }
}

final class FoundationPhase8ReleaseProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->singleton(
            FoundationPhase8ReleaseProbe::class,
            FactoryDefinition::construct(
                FoundationPhase8ReleaseProbe::class,
                [new ServiceReference(RuntimeMode::class)],
            ),
        );
        $builder->scoped(
            FoundationPhase8ReleaseScopedProbe::class,
            FactoryDefinition::construct(FoundationPhase8ReleaseScopedProbe::class),
        );
        foreach ([
            FoundationPhase8ReleaseCliCommand::class,
            FoundationPhase8ReleaseSchedulerCommand::class,
            FoundationPhase8ReleaseWorkerProvider::class,
        ] as $service) {
            $builder->singleton(
                $service,
                FactoryDefinition::construct(
                    $service,
                    [new ServiceReference(FoundationPhase8ReleaseProbe::class)],
                ),
            );
        }
    }
}

final class FoundationPhase8ReleaseWebHandler
{
    public function __invoke(): Response
    {
        return Response::json(['generation' => 'phase8-e2e']);
    }
}

it('builds activates and boots all four runtimes from one immutable Foundation generation', function (): void {
    $project = foundationPhase8ReleaseProject();
    $releaseRoot = $project . '/storage/releases';
    $config = foundationPhase8ReleaseConfig($project);

    try {
        $release = new FoundationReleaseCompiler()->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: [
                'web' => [],
                'cli' => [],
                'worker' => [],
                'scheduler' => [],
            ],
            generation: 'phase8-e2e',
        );

        $generation = $releaseRoot . '/generations/phase8-e2e';
        expect($release['generation'])->toBe('phase8-e2e')
            ->and(new ActiveGeneration()->current($releaseRoot)['generation'])->toBe('phase8-e2e')
            ->and(is_file($generation . '/foundation.php'))->toBeTrue()
            ->and(is_file($generation . '/web/release.json'))->toBeTrue()
            ->and(is_file($generation . '/web/container.php'))->toBeTrue()
            ->and(is_file($generation . '/web/router.php'))->toBeTrue();

        foreach (['cli', 'worker', 'scheduler'] as $runtime) {
            expect(is_file($generation . '/' . $runtime . '/container.php'))->toBeTrue()
                ->and(is_file($generation . '/' . $runtime . '/container.php.meta.json'))->toBeTrue()
                ->and(is_file($generation . '/' . $runtime . '/container.php.foundation.json'))->toBeTrue();
        }

        $trustedFoundationSha256 = hash_file('sha256', $release['manifest']);
        expect($trustedFoundationSha256)->toBeString()
            ->and($trustedFoundationSha256)->toMatch('/^[a-f0-9]{64}$/D');
        if (!is_string($trustedFoundationSha256)) {
            throw new RuntimeException('Unable to hash the generated Foundation manifest.');
        }

        // Build the worker supervisor while source topology is still readable.
        // It must remain unbooted until WorkerManager decides whether a fork is required.
        $sourceWorkerSupervisor = Foundation::worker($config);
        expect($sourceWorkerSupervisor->booted())->toBeFalse();

        // The active web runtime must consume the compiled router rather than
        // rediscovering application routes after publication.
        file_put_contents(
            $project . '/routes/web.php',
            "<?php\n\nthrow new RuntimeException('active release rediscovered source routes');\n",
        );
        $loader = new FoundationReleaseRuntime();
        $web = $loader->webPrevalidated($config, $releaseRoot, $trustedFoundationSha256);
        $response = $web->kernel->handle(Request::fake(
            headers: ['Host' => 'phase8.test'],
            uri: 'https://phase8.test/phase8',
        ));
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('{"generation":"phase8-e2e"}');

        // Trusted non-web process boot must not reconstruct its source graph.
        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\nthrow new RuntimeException('active release rediscovered source providers');\n",
        );

        $cli = $loader->nonWebPrevalidated(
            $config,
            RuntimeMode::Cli,
            $releaseRoot,
            $trustedFoundationSha256,
        );
        $worker = $loader->nonWebPrevalidated(
            $config,
            RuntimeMode::Worker,
            $releaseRoot,
            $trustedFoundationSha256,
        );
        $scheduler = $loader->nonWebPrevalidated(
            $config,
            RuntimeMode::Scheduler,
            $releaseRoot,
            $trustedFoundationSha256,
        );

        expect($cli->application->runtimeMode())->toBe(RuntimeMode::Cli)
            ->and($worker->application->runtimeMode())->toBe(RuntimeMode::Worker)
            ->and($scheduler->application->runtimeMode())->toBe(RuntimeMode::Scheduler)
            ->and($cli->application->make(FoundationPhase8ReleaseProbe::class)->runtime)->toBe(RuntimeMode::Cli)
            ->and($worker->application->make(FoundationPhase8ReleaseProbe::class)->runtime)->toBe(RuntimeMode::Worker)
            ->and($scheduler->application->make(FoundationPhase8ReleaseProbe::class)->runtime)->toBe(RuntimeMode::Scheduler);

        $cliResult = $cli->application->execution()->run(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $cli->application->make(FoundationPhase8ReleaseScopedProbe::class)->sequence,
            ],
            executionId: new ExecutionId('phase8-cli'),
        );
        $workerResult = new WorkerRuntime($worker->application)->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $worker->application->make(FoundationPhase8ReleaseScopedProbe::class)->sequence,
            ],
            executionId: new ExecutionId('phase8-worker'),
        );
        $schedulerResult = new SchedulerRuntime($scheduler->application)->execute(
            static fn(ExecutionId $id): array => [
                (string) $id,
                $scheduler->application->make(FoundationPhase8ReleaseScopedProbe::class)->sequence,
            ],
            executionId: new ExecutionId('phase8-scheduler'),
        );

        expect($cliResult[0])->toBe('phase8-cli')
            ->and($workerResult[0])->toBe('phase8-worker')
            ->and($schedulerResult[0])->toBe('phase8-scheduler')
            ->and(count(array_unique([$cliResult[1], $workerResult[1], $schedulerResult[1]])))->toBe(3);

        $bootstrap = new FoundationReleaseBootstrap($releaseRoot, $trustedFoundationSha256);
        $dispatcher = new CommandDispatcher(
            ['base_path' => $project],
            new CommandRegistry([
                FoundationPhase8ReleaseCliCommand::class,
                FoundationPhase8ReleaseSchedulerCommand::class,
            ]),
            'Foundation Phase 8',
            $bootstrap,
        );
        $cliIo = new FoundationPhase8ReleaseIO();
        $schedulerIo = new FoundationPhase8ReleaseIO();
        expect($dispatcher->run(['infbyte', 'phase8:cli'], $cliIo))->toBe(ExitCode::SUCCESS)
            ->and($cliIo->lines)->toContain('cli')
            ->and($dispatcher->run(['infbyte', 'phase8:scheduler'], $schedulerIo))->toBe(ExitCode::SUCCESS)
            ->and($schedulerIo->lines)->toContain('scheduler');

        $envIo = new FoundationPhase8ReleaseIO();
        expect($dispatcher->run(['infbyte', 'phase8:cli', '--env=staging'], $envIo))->toBe(ExitCode::FAILURE)
            ->and(implode("\n", $envIo->errors))->toContain('do not support the --env override');

        $previousRoot = getenv(FoundationReleaseBootstrap::RELEASE_ROOT_ENV);
        $previousSha256 = getenv(FoundationReleaseBootstrap::MANIFEST_SHA256_ENV);
        FoundationPhase8ReleaseWorkerProvider::$observedRuntime = null;
        try {
            foundationPhase8ReleaseSetEnvironment(FoundationReleaseBootstrap::RELEASE_ROOT_ENV, $releaseRoot);
            foundationPhase8ReleaseSetEnvironment(
                FoundationReleaseBootstrap::MANIFEST_SHA256_ENV,
                $trustedFoundationSha256,
            );
            expect(new WorkerManager($sourceWorkerSupervisor)->run('phase8-provider'))->toBe(0)
                ->and(FoundationPhase8ReleaseWorkerProvider::$observedRuntime)->toBe(RuntimeMode::Worker)
                ->and($sourceWorkerSupervisor->booted())->toBeFalse();
        } finally {
            foundationPhase8ReleaseSetEnvironment(
                FoundationReleaseBootstrap::RELEASE_ROOT_ENV,
                is_string($previousRoot) ? $previousRoot : null,
            );
            foundationPhase8ReleaseSetEnvironment(
                FoundationReleaseBootstrap::MANIFEST_SHA256_ENV,
                is_string($previousSha256) ? $previousSha256 : null,
            );
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationPhase8ReleaseRemove($project);
    }
});

it('keeps worker run dispatcher parents unbooted before pooled-worker validation', function (): void {
    $project = foundationPhase8ReleaseProject();
    $config = foundationPhase8ReleaseConfig($project);
    $config['messaging'] = [
        'consumer' => ['transport' => 'memory'],
        'workers' => [
            'parallel' => [
                'transport' => 'memory',
                'queue' => 'default',
                'pool' => [
                    'enabled' => true,
                    'concurrency' => 2,
                ],
            ],
        ],
    ];
    $io = new FoundationPhase8ReleaseIO();

    try {
        $exit = new CommandDispatcher($config, new CommandRegistry())
            ->run(['infbyte', 'worker:run', 'parallel'], $io);
        $errors = implode("\n", $io->errors);

        expect($exit)->toBe(ExitCode::FAILURE)
            ->and($errors)->not->toContain('fork before booting the parent Foundation application')
            ->and($errors)->toContain('process-local');
    } finally {
        foundationPhase8ReleaseRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationPhase8ReleaseConfig(string $project): array
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
    ];
}

function foundationPhase8ReleaseProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase8-release-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap', 0777, true);
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/storage', 0777, true);

    file_put_contents(
        $project . '/bootstrap/providers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['common' => [FoundationPhase8ReleaseProvider::class]];\n",
    );
    file_put_contents(
        $project . '/routes/workers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn ['phase8-provider' => FoundationPhase8ReleaseWorkerProvider::class];\n",
    );
    file_put_contents(
        $project . '/routes/web.php',
        <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/phase8', FoundationPhase8ReleaseWebHandler::class, ['name' => 'phase8.show']);
PHP,
    );

    return $project;
}

function foundationPhase8ReleaseRemove(string $directory): void
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

function foundationPhase8ReleaseSetEnvironment(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_ENV[$name], $_SERVER[$name]);
        putenv($name);

        return;
    }

    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
    putenv($name . '=' . $value);
}
