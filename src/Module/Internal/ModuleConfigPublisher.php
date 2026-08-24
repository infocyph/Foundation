<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Module\Internal;

use Infocyph\Foundation\Application\Application;

final readonly class ModuleConfigPublisher
{
    public function __construct(private Application $application) {}

    /**
     * @param list<string> $configured
     * @return array{published:list<string>,existing:list<string>}
     */
    public function publish(array $configured, bool $force): array
    {
        if ($configured === []) {
            return ['published' => [], 'existing' => []];
        }

        $directory = $this->application->configPath();
        $this->ensureDirectory($directory);
        $existing = [];
        $sources = $this->sources($directory, $configured, $existing, $force);
        $published = $this->publishFiles($directory, $sources, $existing, $force);

        return ['published' => $published, 'existing' => $existing];
    }

    private function discardBackup(string $path): void
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            unlink($path);
        } finally {
            restore_error_handler();
        }
    }

    /** @param array<string,string> $backups */
    private function discardBackups(array $backups): void
    {
        foreach ($backups as $backup) {
            if (is_file($backup)) {
                $this->discardBackup($backup);
            }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create project config directory "%s".', $directory));
        }
    }

    /**
     * @param list<string> $existing
     * @return array{publish:bool,backup:?string}
     */
    private function prepareTarget(string $target, string $temporary, array &$existing, bool $force): array
    {
        if (is_link($target)) {
            if ($force) {
                throw new \RuntimeException(sprintf(
                    'Refusing to force-publish config through symbolic link "%s".',
                    $target,
                ));
            }

            $existing[] = $target;
            $this->unlink($temporary, 'staged config');

            return ['publish' => false, 'backup' => null];
        }
        if (!is_file($target)) {
            return ['publish' => true, 'backup' => null];
        }
        if (!$force) {
            $existing[] = $target;
            $this->unlink($temporary, 'staged config');

            return ['publish' => false, 'backup' => null];
        }

        $backup = $target . '.foundation-' . bin2hex(random_bytes(6)) . '.bak';
        if (!rename($target, $backup)) {
            throw new \RuntimeException(sprintf('Unable to stage existing config "%s".', basename($target)));
        }

        return ['publish' => true, 'backup' => $backup];
    }

    /**
     * @param array<string,string> $sources
     * @param list<string> $existing
     * @return list<string>
     */
    private function publishFiles(string $directory, array $sources, array &$existing, bool $force): array
    {
        if ($sources === []) {
            return [];
        }

        $staged = [];
        $backups = [];
        $published = [];

        try {
            $this->stage($directory, $sources, $staged);
            $this->publishStaged($staged, $backups, $published, $existing, $force);
        } catch (\Throwable $failure) {
            $rollback = $this->rollback($staged, $published, $backups);
            if ($rollback !== []) {
                throw new \RuntimeException(
                    'Module config publication failed and rollback was incomplete: ' . implode('; ', $rollback),
                    0,
                    $failure,
                );
            }

            throw $failure;
        }

        // Publication is committed. Backup cleanup is best-effort finalization;
        // stale backups are safer than undoing successfully published config.
        $this->discardBackups($backups);

        return $published;
    }

    /**
     * @param array<string,string> $staged
     * @param array<string,string> $backups
     * @param list<string> $published
     * @param list<string> $existing
     */
    private function publishStaged(
        array &$staged,
        array &$backups,
        array &$published,
        array &$existing,
        bool $force,
    ): void {
        foreach ($staged as $target => $temporary) {
            $targetState = $this->prepareTarget($target, $temporary, $existing, $force);
            if (!$targetState['publish']) {
                unset($staged[$target]);

                continue;
            }
            if ($targetState['backup'] !== null) {
                $backups[$target] = $targetState['backup'];
            }
            if (!rename($temporary, $target)) {
                throw new \RuntimeException(sprintf('Unable to publish config template "%s".', basename($target)));
            }
            unset($staged[$target]);
            $published[] = $target;
        }
    }

    /**
     * @param array<string,string> $staged
     * @param list<string> $published
     * @param array<string,string> $backups
     * @return list<string>
     */
    private function rollback(array $staged, array $published, array $backups): array
    {
        $errors = [];
        foreach ($staged as $temporary) {
            if (is_file($temporary) && !unlink($temporary)) {
                $errors[] = 'unable to remove staged file ' . $temporary;
            }
        }
        foreach ($published as $target) {
            if (is_file($target) && !unlink($target)) {
                $errors[] = 'unable to remove published file ' . $target;
            }
        }
        foreach ($backups as $target => $backup) {
            if (is_file($backup) && !rename($backup, $target)) {
                $errors[] = 'unable to restore backup ' . $backup;
            }
        }

        return $errors;
    }

    private function source(string $filename): string
    {
        $source = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'config'
            . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($source) || !is_readable($source)) {
            throw new \RuntimeException(sprintf('Config template "%s" is unavailable.', $filename));
        }

        return $source;
    }

    /**
     * @param list<string> $configured
     * @param list<string> $existing
     * @return array<string,string>
     */
    private function sources(string $directory, array $configured, array &$existing, bool $force): array
    {
        $sources = [];
        foreach ($configured as $filename) {
            if ($filename === '' || basename($filename) !== $filename) {
                continue;
            }

            $target = $directory . DIRECTORY_SEPARATOR . $filename;
            if ($this->targetAlreadyExists($target, $existing, $force)) {
                continue;
            }
            $sources[$target] = $this->source($filename);
        }

        return $sources;
    }

    /**
     * @param array<string,string> $sources
     * @param array<string,string> $staged
     */
    private function stage(string $directory, array $sources, array &$staged): void
    {
        foreach ($sources as $target => $source) {
            $temporary = tempnam($directory, '.foundation-config-');
            if ($temporary === false) {
                throw new \RuntimeException(sprintf('Unable to stage config template "%s".', basename($target)));
            }
            $staged[$target] = $temporary;
            if (!copy($source, $temporary) || !chmod($temporary, 0664)) {
                throw new \RuntimeException(sprintf('Unable to stage config template "%s".', basename($target)));
            }
        }
    }

    /** @param list<string> $existing */
    private function targetAlreadyExists(string $target, array &$existing, bool $force): bool
    {
        if (is_link($target)) {
            if ($force) {
                throw new \RuntimeException(sprintf(
                    'Refusing to force-publish config through symbolic link "%s".',
                    $target,
                ));
            }
            $existing[] = $target;

            return true;
        }
        if (is_file($target) && !$force) {
            $existing[] = $target;

            return true;
        }

        return false;
    }

    private function unlink(string $path, string $kind): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove %s "%s".', $kind, $path));
        }
    }
}
