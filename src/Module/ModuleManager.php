<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigCacheManager;
use Infocyph\Foundation\Module\Internal\ModuleConfigPublisher;
use Infocyph\Foundation\Process\ProcessOptions;
use Infocyph\Foundation\Process\ProcessResult;
use Infocyph\Foundation\Process\ProcessRunner;

final readonly class ModuleManager
{
    public function __construct(
        private Application $application,
        private ModuleCatalog $catalog,
        private ProcessRunner $processes,
    ) {}

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return new ModuleStateResolver($this->application, $this->catalog)->all();
    }

    public function install(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true || $definition['packages'] === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'require'];
        foreach ($definition['packages'] as $package => $constraint) {
            $command[] = $package . ':' . $constraint;
        }
        $command[] = '--with-all-dependencies';
        $command[] = '--update-no-dev';
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: true,
        ));
    }

    /** @return array{published:list<string>,existing:list<string>} */
    public function publishConfig(string $module, bool $force = false): array
    {
        $configured = $this->catalog->resolve($module)['config'];
        $result = new ModuleConfigPublisher($this->application)->publish($configured, $force);
        if ($result['published'] !== []) {
            new ConfigCacheManager($this->application)->clear();
        }

        return $result;
    }

    public function remove(string $module, bool $dryRun = false): ProcessResult
    {
        $definition = $this->catalog->resolve($module);
        if (($definition['built_in'] ?? false) === true) {
            throw new \InvalidArgumentException(sprintf('Module "%s" is built into Foundation.', $definition['name']));
        }

        $ownership = new ModuleStateResolver($this->application, $this->catalog)->rootRequirements();
        if (!$ownership['known']) {
            throw new \RuntimeException(
                'Unable to determine direct Composer ownership: '
                . ($ownership['error'] ?? 'application composer.json is unavailable.'),
            );
        }

        $packages = array_values(array_filter(
            array_keys($definition['packages']),
            static fn(string $package): bool => isset($ownership['requirements'][$package]),
        ));
        if ($packages === []) {
            return new ProcessResult(0);
        }

        $command = ['composer', 'remove', ...$packages, '--with-all-dependencies', '--update-no-dev'];
        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $this->processes->run($command, new ProcessOptions(
            cwd: $this->application->basePath(),
            interactive: true,
        ));
    }
}
