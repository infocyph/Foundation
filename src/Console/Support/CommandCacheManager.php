<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Console\Discovery\CommandManifestCompiler;
use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Console\FoundationConsole;

final readonly class CommandCacheManager
{
    public function __construct(private Application $application) {}

    public function clear(string $path): bool
    {
        $manifest = $this->path($path);
        $entryPrefix = pathinfo(basename($manifest), PATHINFO_FILENAME) . '-';
        $removed = $this->removeFile($manifest, 'command manifest');
        $removed = $this->removeEntries(
            dirname($manifest) . DIRECTORY_SEPARATOR . $entryPrefix . '*.php',
        ) || $removed;

        return $this->removeLegacyDirectory($manifest . '.d') || $removed;
    }

    public function path(string $path): string
    {
        $path = $path !== '' ? $path : 'bootstrap/cache/console/commands.php';

        return $this->absolute($path)
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }

    public function write(string $path, string $routesFile = 'routes/console.php'): string
    {
        $manifest = $this->path($path);
        $routePath = $this->absolute($routesFile)
            ? $routesFile
            : $this->application->basePath(trim($routesFile, DIRECTORY_SEPARATOR));
        $commands = [];

        if (is_file($routePath)) {
            $commands = require $routePath;
            if (!is_array($commands)) {
                throw new \UnexpectedValueException(sprintf(
                    'Console route file "%s" must return a command-to-class map.',
                    $routePath,
                ));
            }
        }

        new CommandManifestCompiler()->write(
            FoundationConsole::commands($commands),
            $manifest,
        );

        return $manifest;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    private function removeEntries(string $pattern): bool
    {
        $removed = false;
        foreach (glob($pattern) ?: [] as $entry) {
            $removed = $this->removeFile($entry, 'command manifest entry') || $removed;
        }

        return $removed;
    }

    private function removeFile(string $path, string $type): bool
    {
        if (!is_file($path)) {
            return false;
        }
        if (!unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove %s "%s".', $type, $path));
        }

        return true;
    }

    private function removeLegacyDirectory(string $directory): bool
    {
        if (!is_dir($directory)) {
            return false;
        }

        $this->removeEntries($directory . DIRECTORY_SEPARATOR . '*.php');
        if (!rmdir($directory)) {
            throw new \RuntimeException(sprintf('Unable to remove command manifest directory "%s".', $directory));
        }

        return true;
    }
}
