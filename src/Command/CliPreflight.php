<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Composer\InstalledVersions;

final readonly class CliPreflight
{
    public function __construct(private CommandCatalog $catalog = new CommandCatalog()) {}

    /**
     * Handle metadata-only invocations without constructing Foundation Application.
     * Returns an exit code when handled, otherwise null to continue to command execution.
     *
     * @param list<string> $argv
     */
    public function handle(array $argv, CommandIO $io): ?int
    {
        $input = ParsedInput::fromArgv($argv);
        if ($input->flag('version') || in_array('-V', $input->raw, true)) {
            $io->writeln('Foundation ' . $this->version());
            return 0;
        }

        if ($input->command === '' || $input->command === 'list') {
            $this->renderList($io);
            return 0;
        }

        if ($input->command === 'help') {
            $name = $input->argument(0);
            $definition = $name === null ? null : $this->catalog->find($name);
            if ($definition === null) {
                $this->renderList($io);
                return $name === null ? 0 : 1;
            }
            $io->writeln($definition->name . ' - ' . $definition->description);
            $io->writeln('Runtime: ' . $definition->runtime->value);
            if ($definition->capabilities !== []) {
                $io->writeln('Capabilities: ' . implode(', ', $definition->capabilities));
            }
            return 0;
        }

        if ($input->command === 'completion') {
            foreach (array_keys($this->catalog->all()) as $name) {
                $io->writeln($name);
            }
            return 0;
        }

        return null;
    }

    private function renderList(CommandIO $io): void
    {
        $groups = [];
        foreach ($this->catalog->all() as $definition) {
            $groups[$definition->group][] = $definition;
        }

        foreach ($groups as $group => $definitions) {
            $io->writeln($group . ':');
            foreach ($definitions as $definition) {
                $io->writeln(sprintf('  %-28s %s', $definition->name, $definition->description));
            }
            $io->writeln();
        }
    }

    private function version(): string
    {
        return InstalledVersions::isInstalled('infocyph/foundation')
            ? (InstalledVersions::getPrettyVersion('infocyph/foundation') ?? 'dev')
            : 'dev';
    }
}
