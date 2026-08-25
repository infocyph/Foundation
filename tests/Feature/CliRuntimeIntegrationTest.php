<?php

declare(strict_types=1);

use Infocyph\CacheLayer\Cache\Lock\FileLockProvider;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandExecutionPolicy;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Command\CommandIO;
use Infocyph\Foundation\Command\CommandRegistry;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Command\OverlapMode;

final class FoundationCliRuntimeIO implements CommandIO
{
    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $lines = [];

    /** @var list<mixed> */
    public array $payloads = [];

    /** @var list<string> */
    public array $writes = [];

    public function __construct(private readonly bool $machine = false) {}

    public function choice(string $question, array $choices, ?string $default = null): string
    {
        unset($question, $choices, $default);

        throw new LogicException('Choice input is not expected in CLI runtime tests.');
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
        $this->payloads[] = $value;
    }

    public function machineReadable(): bool
    {
        return $this->machine;
    }

    public function note(string $message): void
    {
        $this->lines[] = $message;
    }

    public function password(string $question): string
    {
        unset($question);

        throw new LogicException('Password input is not expected in CLI runtime tests.');
    }

    public function quiet(): bool
    {
        return false;
    }

    public function read(string $question, ?string $default = null): string
    {
        unset($question, $default);

        throw new LogicException('Text input is not expected in CLI runtime tests.');
    }

    public function success(string $message): void
    {
        $this->lines[] = $message;
    }

    public function table(array $headers, array $rows): void
    {
        $this->payloads[] = ['headers' => $headers, 'rows' => $rows];
    }

    public function warning(string $message): void
    {
        $this->lines[] = $message;
    }

    public function write(string $message): void
    {
        $this->writes[] = $message;
    }

    public function writeln(string $message = ''): void
    {
        $this->lines[] = $message;
    }

    public function text(): string
    {
        return implode("\n", [...$this->lines, ...$this->writes]);
    }
}

final readonly class FoundationCliProbeCommand implements CommandHandlerInterface
{
    public function __construct(private Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('cli:probe')
            ->description('Probe CLI parsing and application configuration.')
            ->group('Testing')
            ->alias('cprobe')
            ->argument('target', 'Probe target.', required: true)
            ->option('mode', 'Probe mode.', short: 'm', acceptsValue: true)
            ->option('feature', 'Feature toggle.', negatable: true)
            ->option('tag', 'Repeatable tag.', short: 't', acceptsValue: true, multiple: true);
    }

    public function run(CommandContext $context): int
    {
        $input = $context->input();
        $payload = [
            'target' => $input->argument(0),
            'mode' => $input->option('mode'),
            'feature_present' => $input->hasOption('feature'),
            'feature' => $input->flag('feature'),
            'tags' => $input->values('tag'),
            'verbosity' => $input->verbosity(),
            'environment' => $this->application->config()->get('app.env'),
            'json' => $input->flag('json'),
            'no_interaction' => $input->flag('no-interaction'),
        ];

        if ($input->flag('json')) {
            $context->io()->json($payload);
        } else {
            $context->io()->info('probe:' . (string) $input->argument(0));
        }

        return ExitCode::SUCCESS;
    }
}

final class FoundationCliExitCommand implements CommandHandlerInterface
{
    public static function define(CommandDefinition $command): void
    {
        $command->name('cli:exit')->description('Return a deliberate non-zero exit code.')->group('Testing');
    }

    public function run(CommandContext $context): int
    {
        unset($context);

        return 23;
    }
}

final class FoundationCliThrowCommand implements CommandHandlerInterface
{
    public static function define(CommandDefinition $command): void
    {
        $command->name('cli:throw')->description('Throw a deliberate command failure.')->group('Testing');
    }

    public function run(CommandContext $context): int
    {
        unset($context);

        throw new RuntimeException('cli fixture failure');
    }
}

final readonly class FoundationCliOverlapCommand implements CommandHandlerInterface
{
    public function __construct(private Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('cli:overlap')
            ->description('Exercise overlap skip coordination.')
            ->group('Testing')
            ->execution(new CommandExecutionPolicy(
                overlap: OverlapMode::Skip,
                mutex: 'foundation-cli-runtime-overlap',
                leaseSeconds: 5.0,
            ));
    }

    public function run(CommandContext $context): int
    {
        unset($context);
        file_put_contents($this->application->basePath('storage/cli-overlap-ran'), 'ran');

        return ExitCode::SUCCESS;
    }
}

final class FoundationCliHiddenCommand implements CommandHandlerInterface
{
    public static function define(CommandDefinition $command): void
    {
        $command->name('cli:hidden')->description('Hidden command.')->group('Testing')->hidden();
    }

    public function run(CommandContext $context): int
    {
        unset($context);

        return ExitCode::SUCCESS;
    }
}

it('discovers source commands and gives a valid manifest precedence with invalid-cache fallback', function (): void {
    $project = foundationCliRuntimeProject();

    try {
        $source = foundationCliRuntimeDispatcher($project);
        expect($source->registry()->find('cli:probe'))->not->toBeNull()
            ->and($source->registry()->find('cprobe'))->not->toBeNull();

        foundationCliRuntimeWriteManifest($project, new CommandRegistry([
            'cli:exit' => FoundationCliExitCommand::class,
        ]));
        $cached = foundationCliRuntimeDispatcher($project);
        expect($cached->registry()->find('cli:exit'))->not->toBeNull()
            ->and($cached->registry()->find('cli:probe'))->toBeNull();

        file_put_contents(
            $project . '/bootstrap/cache/commands.php',
            "<?php\n\nreturn ['version' => 999, 'commands' => []];\n",
        );
        $fallback = foundationCliRuntimeDispatcher($project);
        expect($fallback->registry()->find('cli:probe'))->not->toBeNull()
            ->and($fallback->registry()->find('cli:exit'))->toBeNull();
    } finally {
        foundationCliRuntimeRemoveDirectory($project);
    }
});

it('handles list version help completion hidden commands and suggestions through preflight', function (): void {
    $project = foundationCliRuntimeProject();

    try {
        $dispatcher = foundationCliRuntimeDispatcher($project);

        $list = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte'], $list))->toBe(ExitCode::SUCCESS)
            ->and($list->text())->toContain('Global options:', 'cli:probe')
            ->and($list->text())->not->toContain('cli:hidden');

        $version = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', '--version'], $version))->toBe(ExitCode::SUCCESS)
            ->and($version->text())->toContain('Foundation Test ');

        $help = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:probe', '--help'], $help))->toBe(ExitCode::SUCCESS)
            ->and($help->text())->toContain('Usage: infbyte cli:probe', '--mode=VALUE', '--no-feature');

        $names = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'completion'], $names))->toBe(ExitCode::SUCCESS)
            ->and($names->text())->toContain('cli:probe', 'cprobe')
            ->and($names->text())->not->toContain('cli:hidden');

        foreach (['bash', 'zsh', 'fish'] as $shell) {
            $completion = new FoundationCliRuntimeIO();
            expect($dispatcher->run(['infbyte', 'completion', $shell], $completion))->toBe(ExitCode::SUCCESS)
                ->and($completion->text())->toContain('cli:probe');
        }

        $unsupported = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'completion', 'powershell'], $unsupported))->toBe(ExitCode::INVALID_USAGE)
            ->and(implode("\n", $unsupported->errors))->toContain('expected bash, zsh, or fish');

        $hidden = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:hidden'], $hidden))->toBe(ExitCode::COMMAND_NOT_FOUND);

        $unknown = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:proeb'], $unknown))->toBe(ExitCode::COMMAND_NOT_FOUND)
            ->and(implode("\n", $unknown->errors))->toContain('Did you mean: cli:probe');
    } finally {
        foundationCliRuntimeRemoveDirectory($project);
    }
});

it('parses descriptor and global options and preserves machine-readable command data', function (): void {
    $project = foundationCliRuntimeProject();

    try {
        $dispatcher = foundationCliRuntimeDispatcher($project);
        $io = new FoundationCliRuntimeIO(machine: true);
        $exit = $dispatcher->run([
            'infbyte',
            'cli:probe',
            'customer-42',
            '--mode',
            'safe',
            '--tag=one',
            '-t',
            'two',
            '--no-feature',
            '-vv',
            '--env=staging',
            '--json',
            '--no-interaction',
        ], $io);

        expect($exit)->toBe(ExitCode::SUCCESS)
            ->and($io->machineReadable())->toBeTrue()
            ->and($io->payloads)->toHaveCount(1)
            ->and($io->payloads[0])->toMatchArray([
                'target' => 'customer-42',
                'mode' => 'safe',
                'feature_present' => true,
                'feature' => false,
                'tags' => ['one', 'two'],
                'verbosity' => 2,
                'environment' => 'staging',
                'json' => true,
                'no_interaction' => true,
            ]);

        $missing = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:probe'], $missing))->toBe(ExitCode::INVALID_USAGE)
            ->and(implode("\n", $missing->errors))->toContain('Missing required argument "target"');

        $unknown = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:probe', 'target', '--bogus'], $unknown))->toBe(ExitCode::INVALID_USAGE)
            ->and(implode("\n", $unknown->errors))->toContain('Unknown option "--bogus"');

        $excess = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:probe', 'first', 'second'], $excess))->toBe(ExitCode::INVALID_USAGE)
            ->and(implode("\n", $excess->errors))->toContain('Too many command arguments');
    } finally {
        foundationCliRuntimeRemoveDirectory($project);
    }
});

it('propagates handler exits converts exceptions and records command statuses', function (): void {
    $project = foundationCliRuntimeProject(history: true);

    try {
        $dispatcher = foundationCliRuntimeDispatcher($project, history: true);

        expect($dispatcher->run(['infbyte', 'cli:probe', 'ok'], new FoundationCliRuntimeIO()))
            ->toBe(ExitCode::SUCCESS)
            ->and($dispatcher->run(['infbyte', 'cli:exit'], new FoundationCliRuntimeIO()))->toBe(23);

        $failure = new FoundationCliRuntimeIO();
        expect($dispatcher->run(['infbyte', 'cli:throw'], $failure))->toBe(ExitCode::FAILURE)
            ->and(implode("\n", $failure->errors))->toContain('cli fixture failure');

        $records = foundationCliRuntimeHistory($project);
        expect(foundationCliRuntimeStatuses($records, 'cli:probe'))->toBe(['pending', 'running', 'succeeded'])
            ->and(foundationCliRuntimeStatuses($records, 'cli:exit'))->toBe(['pending', 'running', 'failed'])
            ->and(foundationCliRuntimeStatuses($records, 'cli:throw'))->toBe(['pending', 'running', 'failed']);

        $exitRecords = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['name'] ?? null) === 'cli:exit'
                && ($record['status'] ?? null) === 'failed',
        ));
        expect($exitRecords)->toHaveCount(1)
            ->and($exitRecords[0]['exit_code'] ?? null)->toBe(23);
    } finally {
        foundationCliRuntimeRemoveDirectory($project);
    }
});

it('skips overlapping commands under a shared file lock and records cancellation', function (): void {
    $project = foundationCliRuntimeProject(history: true);
    $lockPath = $project . '/storage/cache/locks';
    $locks = new FileLockProvider($lockPath);
    $held = $locks->acquire('foundation-cli-runtime-overlap', 0.0, 5.0);
    expect($held)->not->toBeNull();

    try {
        $dispatcher = foundationCliRuntimeDispatcher($project, history: true, lockPath: $lockPath);
        $io = new FoundationCliRuntimeIO();

        expect($dispatcher->run(['infbyte', 'cli:overlap'], $io))->toBe(ExitCode::SUCCESS)
            ->and($io->text())->toContain('already running; execution skipped')
            ->and(is_file($project . '/storage/cli-overlap-ran'))->toBeFalse()
            ->and(foundationCliRuntimeStatuses(foundationCliRuntimeHistory($project), 'cli:overlap'))
            ->toBe(['pending', 'cancelled']);
    } finally {
        if ($held !== null) {
            $locks->release($held);
        }
        foundationCliRuntimeRemoveDirectory($project);
    }
});

function foundationCliRuntimeDispatcher(
    string $project,
    bool $history = false,
    ?string $lockPath = null,
): CommandDispatcher {
    $config = [
        'base_path' => $project,
        '_config_cache' => false,
        'app' => [
            'base_path' => $project,
            'env' => 'testing',
        ],
        'operations' => [
            'history' => [
                'enabled' => $history,
                'path' => $project . '/storage/logs/executions.jsonl',
            ],
        ],
    ];

    if ($lockPath !== null) {
        $config['cache'] = [
            'default' => 'memory',
            'stores' => ['memory' => ['driver' => 'memory']],
            'lock' => [
                'driver' => 'file',
                'path' => $lockPath,
                'retry_sleep_micros' => 1_000,
            ],
        ];
    }

    return CommandDispatcher::project($config, displayName: 'Foundation Test');
}

function foundationCliRuntimeProject(bool $history = false): string
{
    unset($history);
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-cli-runtime-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    mkdir($project . '/storage', 0777, true);

    $routes = [
        'cli:probe' => FoundationCliProbeCommand::class,
        'cli:exit' => FoundationCliExitCommand::class,
        'cli:throw' => FoundationCliThrowCommand::class,
        'cli:overlap' => FoundationCliOverlapCommand::class,
        'cli:hidden' => FoundationCliHiddenCommand::class,
    ];
    file_put_contents(
        $project . '/routes/console.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($routes, true) . ";\n",
    );

    return $project;
}

function foundationCliRuntimeWriteManifest(string $project, CommandRegistry $registry): void
{
    file_put_contents(
        $project . '/bootstrap/cache/commands.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($registry->toManifest(), true) . ";\n",
    );
}

/** @return list<array<string,mixed>> */
function foundationCliRuntimeHistory(string $project): array
{
    $path = $project . '/storage/logs/executions.jsonl';
    if (!is_file($path)) {
        return [];
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $records = [];
    foreach ($lines as $line) {
        $decoded = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
        if (is_array($decoded)) {
            $records[] = $decoded;
        }
    }

    return $records;
}

/**
 * @param list<array<string,mixed>> $records
 * @return list<string>
 */
function foundationCliRuntimeStatuses(array $records, string $name): array
{
    $statuses = [];
    foreach ($records as $record) {
        if (($record['name'] ?? null) === $name && is_string($record['status'] ?? null)) {
            $statuses[] = $record['status'];
        }
    }

    return $statuses;
}

function foundationCliRuntimeRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_link($path)) {
            unlink($path);
        } elseif (is_dir($path)) {
            foundationCliRuntimeRemoveDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
