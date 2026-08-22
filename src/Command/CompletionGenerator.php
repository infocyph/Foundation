<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

final readonly class CompletionGenerator
{
    public function __construct(private CommandRegistry $registry) {}

    public function generate(string $shell, string $executable = 'infbyte'): string
    {
        return match (strtolower($shell)) {
            'bash' => $this->bash($executable),
            'fish' => $this->fish($executable),
            'zsh' => $this->zsh($executable),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported completion shell "%s"; expected bash, zsh, or fish.',
                $shell,
            )),
        };
    }

    private function bash(string $executable): string
    {
        $function = '_' . preg_replace('/[^A-Za-z0-9_]/', '_', basename($executable));
        $commands = implode(' ', $this->commandNames());
        $options = $this->optionMap();
        $cases = [];
        foreach ($options as $command => $commandOptions) {
            $cases[] = sprintf(
                "        %s) opts=%s ;;",
                escapeshellarg($command),
                escapeshellarg(implode(' ', $commandOptions)),
            );
        }

        return sprintf(
            <<<'BASH'
%s() {
    local cur command opts
    COMPREPLY=()
    cur="${COMP_WORDS[COMP_CWORD]}"
    command="${COMP_WORDS[1]}"

    if [[ $COMP_CWORD -le 1 ]]; then
        COMPREPLY=( $(compgen -W %s -- "$cur") )
        return 0
    fi

    case "$command" in
%s
    esac
    COMPREPLY=( $(compgen -W "$opts" -- "$cur") )
}
complete -F %s %s
BASH,
            $function,
            escapeshellarg($commands),
            implode(PHP_EOL, $cases),
            $function,
            escapeshellarg($executable),
        );
    }

    private function fish(string $executable): string
    {
        $lines = [];
        foreach ($this->registry->visible() as $descriptor) {
            $definition = $descriptor->definition;
            $command = $definition->commandName();
            $lines[] = sprintf(
                'complete -c %s -n "__fish_use_subcommand" -a %s -d %s',
                escapeshellarg($executable),
                escapeshellarg($command),
                escapeshellarg($definition->commandDescription()),
            );
            foreach ($definition->options() as $option) {
                $parts = [
                    'complete -c ' . escapeshellarg($executable),
                    '-n ' . escapeshellarg('__fish_seen_subcommand_from ' . $command),
                    '-l ' . escapeshellarg($option['name']),
                ];
                if ($option['short'] !== null) {
                    $parts[] = '-s ' . escapeshellarg($option['short']);
                }
                if ($option['accepts_value']) {
                    $parts[] = '-r';
                }
                if ($option['description'] !== '') {
                    $parts[] = '-d ' . escapeshellarg($option['description']);
                }
                $lines[] = implode(' ', $parts);
            }
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function zsh(string $executable): string
    {
        $commands = [];
        foreach ($this->registry->visible() as $descriptor) {
            $definition = $descriptor->definition;
            $commands[] = sprintf(
                "%s:%s",
                $this->zshQuote($definition->commandName()),
                $this->zshQuote($definition->commandDescription()),
            );
        }

        return sprintf(
            <<<'ZSH'
#compdef %s

_%s() {
    local -a commands
    commands=(
%s
    )

    if (( CURRENT == 2 )); then
        _describe 'command' commands
        return
    fi

    local command=$words[2]
    case $command in
%s
    esac
}

compdef _%s %s
ZSH,
            $executable,
            preg_replace('/[^A-Za-z0-9_]/', '_', basename($executable)),
            implode(PHP_EOL, array_map(static fn(string $line): string => '        ' . $line, $commands)),
            $this->zshCases(),
            preg_replace('/[^A-Za-z0-9_]/', '_', basename($executable)),
            $executable,
        );
    }

    /** @return list<string> */
    private function commandNames(): array
    {
        $names = [];
        foreach ($this->registry->visible() as $descriptor) {
            $names[] = $descriptor->definition->commandName();
            array_push($names, ...$descriptor->definition->aliases());
        }
        sort($names);

        return array_values(array_unique($names));
    }

    /** @return array<string, list<string>> */
    private function optionMap(): array
    {
        $map = [];
        foreach ($this->registry->visible() as $descriptor) {
            $definition = $descriptor->definition;
            $options = ['--help', '-h', '--quiet', '-q', '--no-interaction', '-n', '--json', '--env='];
            foreach ($definition->options() as $option) {
                $options[] = '--' . $option['name'] . ($option['accepts_value'] ? '=' : '');
                if ($option['negatable']) {
                    $options[] = '--no-' . $option['name'];
                }
                if ($option['short'] !== null) {
                    $options[] = '-' . $option['short'];
                }
            }
            $map[$definition->commandName()] = array_values(array_unique($options));
            foreach ($definition->aliases() as $alias) {
                $map[$alias] = $map[$definition->commandName()];
            }
        }

        return $map;
    }

    private function zshCases(): string
    {
        $cases = [];
        foreach ($this->optionMap() as $command => $options) {
            $specs = array_map(
                static fn(string $option): string => "'" . str_replace("'", "'\\''", $option) . "'",
                $options,
            );
            $cases[] = sprintf(
                "        %s) _arguments %s ;;",
                $this->zshQuote($command),
                implode(' ', $specs),
            );
        }

        return implode(PHP_EOL, $cases);
    }

    private function zshQuote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
