<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Operations\ExecutionHistory;
use Infocyph\Foundation\Operations\RuntimeControl;
use Infocyph\Foundation\Operations\RuntimeProcessRegistry;
use Infocyph\Foundation\Scheduling\ScheduleManager;
use Infocyph\Foundation\Scheduling\SchedulerRuntime;
use Infocyph\InterMix\DI\Support\LifetimeEnum;

final class FoundationSchedulerRuntimeIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<mixed> */
    public array $payloads = [];

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in Scheduler runtime tests.');
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
        unset($message);
    }

    public function interactive(): bool
    {
        return false;
    }

    public function json(mixed $value): void
    {
        $this->payloads[] = $value;
    }

    public function machineReadable(): bool
    {
        return true;
    }

    public function note(string $message): void
    {
        unset($message);
    }

    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in Scheduler runtime tests.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in Scheduler runtime tests.');
    }

    public function success(string $message): void
    {
        unset($message);
    }

    public function table(array $headers, array $rows): void
    {
        $this->payloads[] = ['headers' => $headers, 'rows' => $rows];
    }

    public function warning(string $message): void
    {
        unset($message);
    }

    public function write(string $message): void
    {
        unset($message);
    }

    public function writeln(string $message = ''): void
    {
        unset($message);
    }
}

final class FoundationSchedulerScopedProbe
{
    private static int $next = 0;

    public readonly int $sequence;

    public function __construct()
    {
        $this->sequence = ++self::$next;
    }
}

final class FoundationSchedulerServiceProvider extends ServiceProvider
{
    public function register(Application $app): void
    {
        $app->container()->bind(
            'scheduler.runtime.scoped',
            static fn(): FoundationSchedulerScopedProbe => new FoundationSchedulerScopedProbe(),
            LifetimeEnum::Scoped,
        );
    }
}

final readonly class FoundationSchedulerScheduledMessage
{
    public function __construct(public string $value = 'scheduled') {}
}

final class FoundationSchedulerScheduledMessageFactory
{
    public function __invoke(): FoundationSchedulerScheduledMessage
    {
        return new FoundationSchedulerScheduledMessage();
    }
}

final class FoundationSchedulerScheduledMessageHandler
{
    /** @var list<string> */
    public static array $handled = [];

    public function __invoke(FoundationSchedulerScheduledMessage $message): void
    {
        self::$handled[] = $message->value;
    }
}

beforeEach(function (): void {
    FoundationSchedulerScheduledMessageHandler::$handled = [];
});

it('runs Scheduler execution units in fresh InterMix scopes', function (): void {
    $project = foundationSchedulerProject();

    try {
        $app = Foundation::scheduler(foundationSchedulerConfig($project) + [
            'providers' => ['scheduler' => [FoundationSchedulerServiceProvider::class]],
        ]);
        $app->boot();
        $runtime = new SchedulerRuntime($app);

        $first = $runtime->execute(function () use ($app): array {
            $left = $app->make('scheduler.runtime.scoped');
            $right = $app->make('scheduler.runtime.scoped');

            return [$left->sequence, $right->sequence];
        });
        $second = $runtime->execute(function () use ($app): array {
            $left = $app->make('scheduler.runtime.scoped');
            $right = $app->make('scheduler.runtime.scoped');

            return [$left->sequence, $right->sequence];
        });

        expect($first[0])->toBe($first[1])
            ->and($second[0])->toBe($second[1])
            ->and($first[0])->not->toBe($second[0]);
    } finally {
        foundationSchedulerRemove($project);
    }
});

it('executes due work once and schedule:test runs a named entry regardless of due time', function (): void {
    $project = foundationSchedulerProject();
    $marker = $project . '/storage/scheduler-runs.log';

    try {
        foundationSchedulerRoutes($project, [[
            'key' => 'once',
            'command' => 'sched:ok',
            'arguments' => [$marker, 'once'],
            'cron' => '* * * * *',
        ]]);
        $dispatcher = foundationSchedulerDispatcher($project);

        expect($dispatcher->run(['infbyte', 'schedule:run', '--json'], new FoundationSchedulerRuntimeIO()))
            ->toBe(ExitCode::SUCCESS)
            ->and(foundationSchedulerLines($marker))->toBe(['once']);

        foundationSchedulerRoutes($project, [[
            'key' => 'manual',
            'command' => 'sched:ok',
            'arguments' => [$marker, 'manual'],
            'cron' => '0 0 1 1 *',
        ]]);
        expect(foundationSchedulerDispatcher($project)->run(
            ['infbyte', 'schedule:test', 'manual', '--json'],
            new FoundationSchedulerRuntimeIO(),
        ))->toBe(ExitCode::SUCCESS)
            ->and(foundationSchedulerLines($marker))->toBe(['once', 'manual']);
    } finally {
        foundationSchedulerRemove($project);
    }
});

it('filters due entries deterministically and records non-zero and timeout outcomes', function (): void {
    $project = foundationSchedulerProject();
    $marker = $project . '/storage/scheduler-fixed.log';

    try {
        foundationSchedulerRoutes($project, [
            [
                'key' => 'due',
                'command' => 'sched:ok',
                'arguments' => [$marker, 'due'],
                'cron' => '5 10 25 8 *',
                'timezone' => 'UTC',
            ],
            [
                'key' => 'not-due',
                'command' => 'sched:ok',
                'arguments' => [$marker, 'not-due'],
                'cron' => '6 10 25 8 *',
                'timezone' => 'UTC',
            ],
            [
                'key' => 'failure',
                'command' => 'sched:fail',
                'cron' => '0 0 1 1 *',
            ],
            [
                'key' => 'timeout',
                'command' => 'sched:slow',
                'cron' => '0 0 1 1 *',
                'timeout' => 0.05,
            ],
        ]);
        $app = Foundation::scheduler(foundationSchedulerConfig($project));
        $manager = new ScheduleManager($app);

        $runs = $manager->runDue(now: new DateTimeImmutable('2026-08-25 10:05:00 UTC'));
        expect($runs)->toHaveCount(1)
            ->and($runs[0]->successful())->toBeTrue()
            ->and(foundationSchedulerLines($marker))->toBe(['due']);

        $failure = $manager->runNamed('failure');
        expect($failure->exitCode)->toBe(23)
            ->and($failure->successful())->toBeFalse();
        $failed = (new ExecutionHistory($app))->latest('schedule', 'sched:fail');
        expect($failed['status'] ?? null)->toBe('failed')
            ->and($failed['exit_code'] ?? null)->toBe(23);

        $timeout = $manager->runNamed('timeout');
        expect($timeout->successful())->toBeFalse();
        $timedOut = (new ExecutionHistory($app))->latest('schedule', 'sched:slow');
        expect($timedOut['status'] ?? null)->toBe('timed_out')
            ->and($timedOut['metadata']['reason'] ?? null)->toBe('timed_out');
    } finally {
        foundationSchedulerRemove($project);
    }
});

it('skips overlapping scheduled work under shared CacheLayer ownership and records cancellation', function (): void {
    $project = foundationSchedulerProject();
    $marker = $project . '/storage/scheduler-overlap.log';
    $lockPath = $project . '/storage/cache/locks';

    try {
        foundationSchedulerRoutes($project, [[
            'key' => 'locked',
            'command' => 'sched:ok',
            'arguments' => [$marker, 'locked'],
            'cron' => '* * * * *',
            'without_overlap' => true,
            'lease' => 5.0,
        ]]);
        $app = Foundation::scheduler(foundationSchedulerConfig($project, locks: true));
        $manager = new ScheduleManager($app);
        $entry = $manager->entries()[0];
        $locks = new FileLockProvider($lockPath);
        $held = $locks->acquire(
            'foundation-schedule-' . substr(hash('sha256', $entry->identity()), 0, 44),
            0.0,
            5.0,
        );
        expect($held)->not->toBeNull();

        try {
            $run = $manager->runNamed('locked');
            expect($run->locked)->toBeTrue()
                ->and($run->successful())->toBeFalse()
                ->and(is_file($marker))->toBeFalse();

            $latest = (new ExecutionHistory($app))->latestByMetadata(
                'schedule',
                'schedule_identity',
                $entry->identity(),
            );
            expect($latest['status'] ?? null)->toBe('cancelled')
                ->and($latest['metadata']['reason'] ?? null)->toBe('overlap');
            $executionId = $latest['execution_id'] ?? null;
            expect($executionId)->toBeString();
            $records = (new ExecutionHistory($app))->find($executionId);
            expect(array_column($records, 'status'))->toBe(['pending', 'cancelled']);
        } finally {
            if ($held !== null) {
                $locks->release($held);
            }
        }
    } finally {
        foundationSchedulerRemove($project);
    }
});

it('interrupts schedule work on schedule or runtime control changes and cleans the process registry', function (): void {
    foreach (['schedule', 'runtime'] as $scope) {
        $project = foundationSchedulerProject();
        $marker = $project . '/storage/scheduler-work-' . $scope . '.log';
        $controlPath = $project . '/storage/framework/runtime-control.json';

        try {
            foundationSchedulerRoutes($project, [[
                'key' => 'signal-' . $scope,
                'command' => 'sched:signal',
                'arguments' => [$controlPath, $marker, $scope],
                'cron' => '* * * * *',
            ]]);
            $config = foundationSchedulerConfig($project);
            $dispatcher = CommandDispatcher::project($config, displayName: 'Foundation Scheduler Test');

            expect($dispatcher->run(
                ['infbyte', 'schedule:work', '--sleep=1', '--max-iterations=2'],
                new FoundationSchedulerRuntimeIO(),
            ))->toBe(ExitCode::SUCCESS)
                ->and(foundationSchedulerLines($marker))->toBe([$scope]);

            $app = Foundation::scheduler($config);
            expect((new RuntimeControl($app))->token($scope))->not->toBe('')
                ->and((new RuntimeProcessRegistry($app))->all('schedule', 'default'))->toBe([]);
        } finally {
            foundationSchedulerRemove($project);
        }
    }
});

it('exposes interrupt and reload commands and dispatches scheduled messages through Omnibus', function (): void {
    $project = foundationSchedulerProject();

    try {
        $config = foundationSchedulerConfig($project, messaging: true);
        $dispatcher = CommandDispatcher::project($config, displayName: 'Foundation Scheduler Test');

        expect($dispatcher->run(['infbyte', 'schedule:interrupt', '--json'], new FoundationSchedulerRuntimeIO()))
            ->toBe(ExitCode::SUCCESS);
        $app = Foundation::scheduler($config);
        $control = new RuntimeControl($app);
        expect($control->token('schedule'))->not->toBe('');

        expect($dispatcher->run(['infbyte', 'runtime:reload', '--json'], new FoundationSchedulerRuntimeIO()))
            ->toBe(ExitCode::SUCCESS)
            ->and((new RuntimeControl(Foundation::scheduler($config)))->token('runtime'))->not->toBe('');

        expect($dispatcher->run(
            ['infbyte', 'schedule:dispatch-message', 'heartbeat', '--json'],
            new FoundationSchedulerRuntimeIO(),
        ))->toBe(ExitCode::SUCCESS)
            ->and(FoundationSchedulerScheduledMessageHandler::$handled)->toBe(['scheduled']);
    } finally {
        foundationSchedulerRemove($project);
    }
});

/** @return array<string,mixed> */
function foundationSchedulerConfig(string $project, bool $locks = false, bool $messaging = false): array
{
    $config = [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => [
            'base_path' => $project,
            'env' => 'testing',
        ],
        'operations' => [
            'history' => [
                'enabled' => true,
                'path' => $project . '/storage/logs/executions.jsonl',
            ],
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

    if ($locks) {
        $config['cache'] = [
            'default' => 'memory',
            'stores' => ['memory' => ['driver' => 'memory']],
            'lock' => [
                'driver' => 'file',
                'path' => $project . '/storage/cache/locks',
                'retry_sleep_micros' => 1_000,
            ],
        ];
    }

    if ($messaging) {
        $config['messaging'] = [
            'scheduled_messages' => [
                'heartbeat' => FoundationSchedulerScheduledMessageFactory::class,
            ],
            'handlers' => [
                FoundationSchedulerScheduledMessage::class => FoundationSchedulerScheduledMessageHandler::class,
            ],
            'routes' => [
                FoundationSchedulerScheduledMessage::class => [
                    'transport' => 'sync',
                    'queue' => 'scheduled',
                ],
            ],
        ];
    }

    return $config;
}

function foundationSchedulerDispatcher(string $project): CommandDispatcher
{
    return CommandDispatcher::project(
        foundationSchedulerConfig($project),
        displayName: 'Foundation Scheduler Test',
    );
}

function foundationSchedulerProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-scheduler-runtime-' . bin2hex(random_bytes(5));
    foreach ([
        '/routes',
        '/bootstrap/cache',
        '/storage/cache/locks',
        '/storage/framework',
        '/storage/logs',
    ] as $path) {
        mkdir($project . $path, 0777, true);
    }

    file_put_contents($project . '/infbyte', <<<'PHP'
<?php

declare(strict_types=1);

$command = $argv[1] ?? '';

if ($command === 'sched:ok') {
    file_put_contents($argv[2], ($argv[3] ?? 'ok') . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

if ($command === 'sched:fail') {
    exit(23);
}

if ($command === 'sched:slow') {
    usleep(300_000);
    exit(0);
}

if ($command === 'sched:signal') {
    $scope = $argv[4] ?? 'schedule';
    $state = [
        $scope => [
            'token' => 'child-' . bin2hex(random_bytes(6)),
            'signaled_at' => gmdate(DATE_ATOM),
        ],
    ];
    file_put_contents(
        $argv[2],
        json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        LOCK_EX,
    );
    file_put_contents($argv[3], $scope . PHP_EOL, FILE_APPEND | LOCK_EX);
    exit(0);
}

exit(64);
PHP);

    return $project;
}

/** @param list<array<string,mixed>> $definitions */
function foundationSchedulerRoutes(string $project, array $definitions): void
{
    $normalized = [];
    foreach ($definitions as $definition) {
        $normalized[] = [
            'key' => $definition['key'],
            'command' => $definition['command'],
            'arguments' => $definition['arguments'] ?? [],
            'cron' => $definition['cron'] ?? '* * * * *',
            'timezone' => $definition['timezone'] ?? 'UTC',
            'without_overlap' => $definition['without_overlap'] ?? false,
            'on_one_server' => $definition['on_one_server'] ?? false,
            'lease' => $definition['lease'] ?? 300.0,
            'wait' => $definition['wait'] ?? 0.0,
            'timeout' => $definition['timeout'] ?? null,
            'memory' => $definition['memory'] ?? null,
        ];
    }
    $export = var_export($normalized, true);
    $source = <<<PHP
<?php

declare(strict_types=1);

use Infocyph\Foundation\Scheduling\Schedule;

return static function (Schedule \$schedule): void {
    \$definitions = {$export};
    foreach (\$definitions as \$definition) {
        \$command = \$schedule->command(\$definition['command'])
            ->arguments(\$definition['arguments'])
            ->key(\$definition['key'])
            ->cron(\$definition['cron'])
            ->timezone(\$definition['timezone']);
        if (\$definition['without_overlap']) {
            \$command->withoutOverlap(true, \$definition['lease'], \$definition['wait']);
        }
        if (\$definition['on_one_server']) {
            \$command->onOneServer(true, \$definition['lease'], \$definition['wait']);
        }
        if (\$definition['timeout'] !== null) {
            \$command->timeout(\$definition['timeout']);
        }
        if (\$definition['memory'] !== null) {
            \$command->memoryLimit(\$definition['memory']);
        }
    }
};
PHP;

    file_put_contents($project . '/routes/schedule.php', $source);
}

/** @return list<string> */
function foundationSchedulerLines(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    return is_array($lines) ? array_values($lines) : [];
}

function foundationSchedulerRemove(string $directory): void
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
