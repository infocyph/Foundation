<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;

final class CommandDispatcher
{
    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        private CommandRegistry $registry,
        private string $displayName = 'Foundation',
    ) {}

    /**
     * Build the CLI surface without constructing Foundation Application.
     * A valid scalar command manifest wins over routes/console.php. Invalid or
     * incompatible manifests fall back to the source route file.
     *
     * @param array<string, mixed> $config
     */
    public static function project(
        array $config = [],
        ?string $manifestPath = null,
        ?string $routesPath = null,
        string $displayName = 'Foundation',
    ): self {
        $basePath = $config['base_path'] ?? getcwd();
        if (!is_string($basePath) || $basePath === '') {
            throw new \InvalidArgumentException('Command dispatcher requires a non-empty base_path.');
        }
        $config['base_path'] = $basePath;

        $manifestPath ??= $basePath . '/bootstrap/cache/commands.php';
        $routesPath ??= $basePath . '/routes/console.php';
        if (is_file($manifestPath)) {
            try {
                $manifest = require $manifestPath;
                if (is_array($manifest)) {
                    return new self($config, CommandRegistry::fromManifest($manifest), $displayName);
                }
            } catch (\Throwable) {
                // A command cache is an optimization. Source routes remain authoritative.
            }
        }

        $commands = [];
        if (is_file($routesPath)) {
            $commands = require $routesPath;
            if (!is_array($commands)) {
                throw new \UnexpectedValueException(sprintf(
                    'Command route file "%s" must return a command map.',
                    $routesPath,
                ));
            }
        }

        return new self($config, new CommandRegistry($commands), $displayName);
    }

    public function registry(): CommandRegistry
    {
        return $this->registry;
    }

    /** @param list<string> $argv */
    public function run(array $argv, ?CommandIO $io = null): int
    {
        $coarse = ParsedInput::fromArgv($argv);
        $io ??= TerminalIO::fromInput($coarse);
        $preflight = new CliPreflight($this->registry, $this->displayName);

        try {
            $handled = $preflight->handle($argv, $io);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }
        if ($handled !== null) {
            return $handled;
        }

        $descriptor = $this->registry->find($coarse->command);
        if ($descriptor === null || $descriptor->definition->isHidden()) {
            $io->error(sprintf('Command "%s" is not defined.', $coarse->command));
            $suggestions = $this->registry->suggestions($coarse->command);
            if ($suggestions !== []) {
                $io->error('Did you mean: ' . implode(', ', $suggestions) . '?');
            }

            return ExitCode::COMMAND_NOT_FOUND;
        }

        try {
            $input = ParsedInput::fromArgv($argv, $descriptor->definition);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        if ($descriptor->handler === null) {
            $io->error(sprintf(
                'System command "%s" is not yet bound to a Foundation handler.',
                $descriptor->definition->commandName(),
            ));

            return ExitCode::CANNOT_EXECUTE;
        }

        try {
            $application = $this->application($descriptor->definition->commandRuntime(), $input);
            $inline = static fn(ExecutionId $executionId): int => new CommandResolver($application->boot())
                ->run($descriptor, $input, $io, $executionId);

            return new CommandExecutionCoordinator(
                $application,
                executable: $argv[0] ?? null,
            )->run($descriptor, $argv, $inline, $io);
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class);

            return ExitCode::FAILURE;
        }
    }

    private function application(RuntimeMode $runtime, ParsedInput $input): Application
    {
        $config = $this->config;
        $environment = $input->option('env');
        if ($environment !== null && $environment !== '') {
            $app = $config['app'] ?? [];
            if (!is_array($app)) {
                throw new \UnexpectedValueException('Inline app configuration must be an array.');
            }
            $app['env'] = $environment;
            $config['app'] = $app;
        }

        return match ($runtime) {
            RuntimeMode::Cli => Foundation::cli($config),
            RuntimeMode::Scheduler => Foundation::scheduler($config),
            RuntimeMode::Web => Foundation::web($config),
            RuntimeMode::Worker => Foundation::worker($config),
        };
    }
}
