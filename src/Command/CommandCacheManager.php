<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Command;

use Infocyph\Foundation\Application\Application;

final readonly class CommandCacheManager
{
    public function __construct(
        private Application $application,
        private CommandCatalog $catalog = new CommandCatalog(),
    ) {}

    public function clear(string $path = 'bootstrap/cache/commands.php'): bool
    {
        $path = $this->absolute($path);
        if (!is_file($path)) {
            return false;
        }
        if (!unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove command cache "%s".', $path));
        }
        return true;
    }

    public function write(string $path = 'bootstrap/cache/commands.php'): string
    {
        $path = $this->absolute($path);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create command cache directory "%s".', $directory));
        }

        $payload = [];
        foreach ($this->catalog->all() as $name => $definition) {
            $payload[$name] = [
                'description' => $definition->description,
                'group' => $definition->group,
                'runtime' => $definition->runtime->value,
                'capabilities' => $definition->capabilities,
            ];
        }
        $temporary = tempnam($directory, '.commands-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create command cache staging file.');
        }
        try {
            $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
            if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to publish command cache "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) { unlink($temporary); }
        }

        return $path;
    }

    private function absolute(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }
}
