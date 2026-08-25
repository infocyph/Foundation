<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;

final readonly class CommandDispatcher
{
    private const string SUPERVISED_ENV = 'INFOCYPH_FOUNDATION_SUPERVISED';

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
                    return new self(
                        $config,
                        CommandRegistry::fromManifest(self::associative($manifest)),
                        $displayName,
                    );
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
        $profile = $coarse->flag('profile')
            && !$coarse->flag('silent')
            && getenv(self::SUPERVISED_ENV) !== '1';
        $startedAt = $profile ? hrtime(true) : 0;
        $baselinePeak = $profile ? memory_get_peak_usage(true) : 0;
        $io ??= TerminalIO::fromInput($coarse);
        $preflight = new CliPreflight($this->registry, $this->displayName);

        try {
            $handled = $preflight->handle($argv, $io);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return ExitCode::INVALID_USAGE;
        }
        if ($handled !== null) {
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return $handled;
        }

        $descriptor = $this->descriptor($coarse, $io);
        if ($descriptor === null) {
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return ExitCode::COMMAND_NOT_FOUND;
        }

        try {
            $input = ParsedInput::fromArgv($argv, $descriptor->definition);
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return ExitCode::INVALID_USAGE;
        }

        if ($descriptor->handler === null) {
            $io->error(sprintf(
                'System command "%s" is not yet bound to a Foundation handler.',
                $descriptor->definition->commandName(),
            ));
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return ExitCode::CANNOT_EXECUTE;
        }

        try {
            $application = $this->application($descriptor->definition->commandRuntime(), $input);
            $inline = static fn(ExecutionId $executionId): int => new CommandResolver($application->boot())
                ->run($descriptor, $input, $io, $executionId);

            $exit = new CommandExecutionCoordinator(
                $application,
                executable: $argv[0] ?? null,
            )->run($descriptor, $argv, $inline, $io);
            $this->profile($input, $profile, $startedAt, $baselinePeak);

            return $exit;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage() !== '' ? $exception->getMessage() : $exception::class);
            $this->profile($coarse, $profile, $startedAt, $baselinePeak);

            return ExitCode::FAILURE;
        }
    }

    /**
     * @param array<int|string,mixed> $value
     * @return array<string,mixed>
     */
    private static function associative(array $value): array
    {
        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Compiled command manifest must use string keys.');
            }
            $normalized[$key] = $item;
        }

        return $normalized;
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

    private function descriptor(ParsedInput $input, CommandIO $io): ?CommandDescriptor
    {
        $descriptor = $this->registry->find($input->command);
        if ($descriptor !== null && !$descriptor->definition->isHidden()) {
            return $descriptor;
        }

        $io->error(sprintf('Command "%s" is not defined.', $input->command));
        $suggestions = $this->registry->suggestions($input->command);
        if ($suggestions !== []) {
            $io->error('Did you mean: ' . implode(', ', $suggestions) . '?');
        }

        return null;
    }

    private function profile(ParsedInput $input, bool $enabled, int|float $startedAt, int $baselinePeak): void
    {
        if (!$enabled) {
            return;
        }

        $profile = [
            'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 3),
            'peak_memory_bytes' => memory_get_peak_usage(true),
            'peak_memory_growth_bytes' => max(0, memory_get_peak_usage(true) - $baselinePeak),
            'verbosity' => $input->verbosity(),
        ];
        if ($input->flag('json')) {
            fwrite(STDERR, json_encode(
                ['profile' => $profile],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) . PHP_EOL);

            return;
        }

        fwrite(STDERR, sprintf(
            'Profile: %.3f ms; peak %.2f MiB; growth %.2f MiB; verbosity %d%s',
            $profile['duration_ms'],
            $profile['peak_memory_bytes'] / 1_048_576,
            $profile['peak_memory_growth_bytes'] / 1_048_576,
            $profile['verbosity'],
            PHP_EOL,
        ));
    }
}
