<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Command\CommandContext;
use Infocyph\Foundation\Command\CommandDefinition;
use Infocyph\Foundation\Command\CommandDispatcher;
use Infocyph\Foundation\Command\CommandHandlerInterface;
use Infocyph\Foundation\Command\CommandCatalog;
use Infocyph\Foundation\Command\ExitCode;
use Infocyph\Foundation\Command\TerminalIO;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class FoundationCliScopeFreeCommand implements CommandHandlerInterface
{
    public static bool $executionIdVisible = false;

    public function __construct(private Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command->name('cli:scope-free')->description('Prove plain CLI commands remain scope-free.');
    }

    public function run(CommandContext $context): int
    {
        unset($context);

        try {
            $this->application->make(ExecutionId::class);
            self::$executionIdVisible = true;
        } catch (ServiceResolutionException) {
            self::$executionIdVisible = false;
        }

        return ExitCode::SUCCESS;
    }
}

final readonly class FoundationCliScopedCommand implements CommandHandlerInterface
{
    public static bool $contextSeedMatches = false;

    public static bool $definitionSeedMatches = false;

    public static ?string $executionId = null;

    public function __construct(private Application $application) {}

    public static function define(CommandDefinition $command): void
    {
        $command
            ->name('cli:scoped')
            ->description('Prove explicit CLI execution scope seeding.')
            ->scope();
    }

    public function run(CommandContext $context): int
    {
        $resolvedContext = $this->application->make(CommandContext::class);
        $resolvedDefinition = $this->application->make(CommandDefinition::class);
        $resolvedExecutionId = $this->application->make(ExecutionId::class);

        self::$contextSeedMatches = $resolvedContext === $context;
        self::$definitionSeedMatches = $resolvedDefinition === $context->descriptor()->definition;
        self::$executionId = (string) $resolvedExecutionId;

        return ExitCode::SUCCESS;
    }
}

it('keeps plain CLI commands scope-free and enters scope only when execution state is required', function (): void {
    FoundationCliScopeFreeCommand::$executionIdVisible = false;
    FoundationCliScopedCommand::$contextSeedMatches = false;
    FoundationCliScopedCommand::$definitionSeedMatches = false;
    FoundationCliScopedCommand::$executionId = null;

    $project = foundationCliScopeSelectionProject();

    try {
        $dispatcher = CommandDispatcher::project([
            'base_path' => $project,
            '_config_cache' => false,
            'app' => [
                'base_path' => $project,
                'env' => 'testing',
            ],
        ], displayName: 'Foundation CLI Scope Test');
        $io = new TerminalIO(silentMode: true);

        expect($dispatcher->run(['infbyte', 'cli:scope-free'], $io))->toBe(ExitCode::SUCCESS)
            ->and(FoundationCliScopeFreeCommand::$executionIdVisible)->toBeFalse()
            ->and($dispatcher->run(['infbyte', 'cli:scoped'], $io))->toBe(ExitCode::SUCCESS)
            ->and(FoundationCliScopedCommand::$contextSeedMatches)->toBeTrue()
            ->and(FoundationCliScopedCommand::$definitionSeedMatches)->toBeTrue()
            ->and(FoundationCliScopedCommand::$executionId)->not->toBeNull()->not->toBe('');
    } finally {
        foundationCliScopeSelectionRemove($project);
    }
});

it('preserves explicit and database-required scope policy in compiled command metadata', function (): void {
    $explicit = (new CommandDefinition('cli:explicit-scope'))->scope();
    $database = (new CommandDefinition('cli:database-scope'))->capability('db');
    $plain = new CommandDefinition('cli:plain');

    expect($explicit->requiresExecutionScope())->toBeTrue()
        ->and(CommandDefinition::fromManifest($explicit->toManifest())->requiresExecutionScope())->toBeTrue()
        ->and($database->requiresExecutionScope())->toBeTrue()
        ->and(CommandDefinition::fromManifest($database->toManifest())->requiresExecutionScope())->toBeTrue()
        ->and($plain->requiresExecutionScope())->toBeFalse()
        ->and(CommandDefinition::fromManifest($plain->toManifest())->requiresExecutionScope())->toBeFalse();
});

it('marks every module command with database connection selection as database scoped', function (): void {
    $catalog = new CommandCatalog();

    foreach ([
        'module:install',
        'module:show',
        'module:schema:install',
        'module:schema:status',
        'module:schema:sync',
    ] as $name) {
        $definition = $catalog->find($name);

        expect($definition)->not->toBeNull()
            ->and($definition?->capabilities())->toContain('db')
            ->and($definition?->requiresExecutionScope())->toBeTrue();
    }
});

function foundationCliScopeSelectionProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-cli-scope-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    mkdir($project . '/storage', 0777, true);

    file_put_contents(
        $project . '/routes/console.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'cli:scope-free' => FoundationCliScopeFreeCommand::class,
            'cli:scoped' => FoundationCliScopedCommand::class,
        ], true) . ";\n",
    );

    return $project;
}

function foundationCliScopeSelectionRemove(string $directory): void
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
        if (is_dir($path) && !is_link($path)) {
            foundationCliScopeSelectionRemove($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}
