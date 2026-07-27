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
        $removed = false;

        if (is_file($manifest)) {
            if (!unlink($manifest)) {
                throw new \RuntimeException(sprintf('Unable to remove command manifest "%s".', $manifest));
            }
            $removed = true;
        }

        $entryDirectory = $manifest . '.d';
        if (!is_dir($entryDirectory)) {
            return $removed;
        }

        $entries = glob($entryDirectory . DIRECTORY_SEPARATOR . '*.php') ?: [];
        foreach ($entries as $entry) {
            if (!unlink($entry)) {
                throw new \RuntimeException(sprintf('Unable to remove command manifest entry "%s".', $entry));
            }
            $removed = true;
        }
        if (!rmdir($entryDirectory)) {
            throw new \RuntimeException(sprintf('Unable to remove command manifest directory "%s".', $entryDirectory));
        }

        return $removed;
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
}
