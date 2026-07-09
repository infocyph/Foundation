<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\EnvParser;
use Infocyph\ArrayKit\Config\Support\Environment;
use Infocyph\Foundation\Support\ValueNormalizer;

final class EnvironmentLoader
{
    /**
     * @var list<string>
     */
    private const array DEFAULT_FILES = ['.env', '.env.local'];

    /**
     * @param array<string, mixed> $inline
     */
    public function load(string $basePath, array $inline = []): void
    {
        $app = ValueNormalizer::associativeArray($inline['app'] ?? null);
        if (!ValueNormalizer::bool($app['load_env'] ?? true, true)) {
            return;
        }

        $protected = array_fill_keys(array_keys(Environment::all(true)), true);
        $loaded = [];

        foreach ($this->files($basePath, $app) as $file) {
            if (!is_file($file)) {
                continue;
            }

            $this->hydrate(
                EnvParser::parseFile($file),
                $protected,
                $loaded,
            );
        }
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @param array<string, mixed> $app
     * @return list<string>
     */
    private function files(string $basePath, array $app): array
    {
        $configured = $app['env_files'] ?? null;
        $files = is_string($configured) && $configured !== ''
            ? [$configured]
            : ValueNormalizer::stringList($configured);

        if ($files === []) {
            $files = self::DEFAULT_FILES;
        }

        $resolved = [];
        foreach ($files as $file) {
            $path = $this->absolute($file)
                ? $file
                : $basePath . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);

            if (!in_array($path, $resolved, true)) {
                $resolved[] = $path;
            }
        }

        return $resolved;
    }

    /**
     * @param array<string, string|null> $variables
     * @param array<string, true> $protected
     * @param array<string, true> $loaded
     */
    private function hydrate(array $variables, array $protected, array &$loaded): void
    {
        foreach ($variables as $key => $value) {
            if ($key === '') {
                continue;
            }

            if (isset($protected[$key]) && !isset($loaded[$key])) {
                continue;
            }

            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
            $loaded[$key] = true;

            if ($value === null) {
                putenv($key);

                continue;
            }

            putenv($key . '=' . $value);
        }
    }
}
