<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Composer\InstalledVersions;

final readonly class CliPreflight
{
    public function __construct(private CommandRegistry $registry = new CommandRegistry()) {}

    /**
     * Handle metadata-only invocations without constructing Foundation Application.
     * Returns an exit code when handled, otherwise null to continue to command execution.
     *
     * @param list<string> $argv
     */
    public function handle(array $argv, CommandIO $io): ?int
    {
        $input = ParsedInput::fromArgv($argv);
        if ($input->flag('version')) {
            $io->writeln('Foundation ' . $this->version());

            return ExitCode::SUCCESS;
        }

        if ($input->flag('help')) {
            return $input->command === '' ? $this->list($io) : $this->helpName($input->command, $io);
        }

        return match ($input->command) {
            '', 'list' => $this->list($io),
            'help' => $this->help($input, $io),
            'completion' => $this->completion($input, $io, $argv[0] ?? 'infbyte'),
            default => null,
        };
    }

    private function completion(ParsedInput $input, CommandIO $io, string $executable): int
    {
        $shell = $input->argument(0);
        if ($shell === null) {
            foreach ($this->registry->visible() as $descriptor) {
                $io->writeln($descriptor->definition->commandName());
                foreach ($descriptor->definition->aliases() as $alias) {
                    $io->writeln($alias);
                }
            }

            return ExitCode::SUCCESS;
        }

        try {
            $io->write(new CompletionGenerator($this->registry)->generate($shell, basename($executable)));
        } catch (\InvalidArgumentException $exception) {
            $io->error($exception->getMessage());

            return ExitCode::INVALID_USAGE;
        }

        return ExitCode::SUCCESS;
    }

    private function help(ParsedInput $input, CommandIO $io): int
    {
        $name = $input->argument(0);
        if ($name === null) {
            return $this->list($io);
        }

        return $this->helpName($name, $io);
    }

    private function helpName(string $name, CommandIO $io): int
    {
        $descriptor = $this->registry->find($name);
        if ($descriptor === null || $descriptor->definition->isHidden()) {
            $io->error(sprintf('Command "%s" is not defined.', $name));
            $this->suggest($name, $io);

            return ExitCode::COMMAND_NOT_FOUND;
        }

        $definition = $descriptor->definition;
        $io->writeln($definition->commandName() . ' - ' . $definition->commandDescription());
        $io->writeln('Usage: ' . $this->usage($definition));
        $io->writeln('Runtime: ' . $definition->commandRuntime()->value);
        if ($definition->aliases() !== []) {
            $io->writeln('Aliases: ' . implode(', ', $definition->aliases()));
        }
        if ($definition->capabilities() !== []) {
            $io->writeln('Capabilities: ' . implode(', ', $definition->capabilities()));
        }

        $arguments = $definition->arguments();
        if ($arguments !== []) {
            $io->writeln();
            $io->writeln('Arguments:');
            foreach ($arguments as $argument) {
                $io->writeln(sprintf(
                    '  %-24s %s',
                    $argument['name'],
                    $argument['description'],
                ));
            }
        }

        $options = $definition->options();
        if ($options !== []) {
            $io->writeln();
            $io->writeln('Options:');
            foreach ($options as $option) {
                $signature = '--' . $option['name'];
                if ($option['negatable']) {
                    $signature .= '/--no-' . $option['name'];
                }
                if ($option['accepts_value']) {
                    $signature .= '=VALUE';
                }
                if ($option['short'] !== null) {
                    $signature = '-' . $option['short'] . ', ' . $signature;
                }
                $io->writeln(sprintf('  %-24s %s', $signature, $option['description']));
            }
        }

        return ExitCode::SUCCESS;
    }

    private function list(CommandIO $io): int
    {
        $groups = [];
        foreach ($this->registry->visible() as $descriptor) {
            $definition = $descriptor->definition;
            $groups[$definition->commandGroup()][] = $definition;
        }
        ksort($groups);

        foreach ($groups as $group => $definitions) {
            usort(
                $definitions,
                static fn(CommandDefinition $left, CommandDefinition $right): int => $left->commandName()
                    <=> $right->commandName(),
            );
            $io->writeln($group . ':');
            foreach ($definitions as $definition) {
                $io->writeln(sprintf(
                    '  %-28s %s',
                    $definition->commandName(),
                    $definition->commandDescription(),
                ));
            }
            $io->writeln();
        }

        return ExitCode::SUCCESS;
    }

    private function suggest(string $name, CommandIO $io): void
    {
        $suggestions = $this->registry->suggestions($name);
        if ($suggestions !== []) {
            $io->error('Did you mean: ' . implode(', ', $suggestions) . '?');
        }
    }

    private function usage(CommandDefinition $definition): string
    {
        $parts = ['php infbyte', $definition->commandName()];
        if ($definition->options() !== []) {
            $parts[] = '[options]';
        }

        foreach ($definition->arguments() as $argument) {
            $name = $argument['name'] . ($argument['variadic'] ? '...' : '');
            $parts[] = $argument['required'] ? '<' . $name . '>' : '[' . $name . ']';
        }

        return implode(' ', $parts);
    }

    private function version(): string
    {
        return InstalledVersions::isInstalled('infocyph/foundation')
            ? (InstalledVersions::getPrettyVersion('infocyph/foundation') ?? 'dev')
            : 'dev';
    }
}
