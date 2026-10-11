<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LazyFileConfig;

final class ArrayKitConfigCache
{
    public const string SINGLE_FILE = 'config.php';

    /** @return array<string,mixed>|null */
    public function loadSingle(string $path): ?array
    {
        $config = new Config();

        try {
            if (!$config->loadCache($path)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        $items = [];
        foreach ($config->all() as $key => $value) {
            if (is_string($key)) {
                $items[$key] = $value;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $config
     * @return list<string>
     */
    public function writeSharded(array $config, string $directory): array
    {
        $this->assertPublicationRoot($directory);
        $this->assertNativeGenerationsSafe($directory);
        $namespaces = [];
        foreach ($config as $namespace => $value) {
            if (is_array($value) && preg_match('/^[A-Za-z0-9_-]+$/', $namespace) === 1) {
                $namespaces[] = $namespace;
            }
        }
        sort($namespaces);

        new LazyFileConfig(
            directory: $directory,
            items: array_intersect_key($config, array_fill_keys($namespaces, true)),
            namespaceCacheDirectory: $directory,
        )->warmNamespaceCache($namespaces);

        $active = $this->activeCacheDirectory($directory);
        foreach ($namespaces as $namespace) {
            $this->validateNamespace($active, $namespace);
        }
        $this->removeStaleFiles($directory, $namespaces);

        return $namespaces;
    }

    /** @param array<string,mixed> $config */
    public function writeSingle(array $config, string $directory): void
    {
        $this->assertPublicationRoot($directory);
        $this->assertNativeGenerationsSafe($directory);

        $native = new Config();
        if (!$native->loadArray($config)) {
            throw new \RuntimeException('Unable to materialize ArrayKit single config cache payload.');
        }

        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::SINGLE_FILE;
        if (!$native->exportCache($path) || !is_file($path) || !chmod($path, 0664)) {
            throw new \RuntimeException(sprintf(
                'Unable to publish ArrayKit single config cache "%s".',
                $path,
            ));
        }

        $materialized = $this->loadSingle($path);
        if ($materialized === null) {
            throw new \RuntimeException(sprintf(
                'Unable to validate ArrayKit single config cache "%s".',
                $path,
            ));
        }

        ConfigExportValidator::assertExportable($materialized);

        // Once the single cache is valid, remove only verified native artifacts.
        $this->removeStaleFiles($directory, [pathinfo(self::SINGLE_FILE, PATHINFO_FILENAME)], keepFlat: false);
        $this->retireNativeGenerationPointer($directory);
    }

    private function activeCacheDirectory(string $directory): string
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $pointer = $root . DIRECTORY_SEPARATOR . '.arraykit-generation';
        if (!is_file($pointer)) {
            return $root;
        }

        $generation = trim((string) file_get_contents($pointer));
        if (preg_match('/^\\.arraykit-gen-[a-f0-9]+$/', $generation) !== 1) {
            throw new \RuntimeException('ArrayKit namespace cache generation pointer is invalid.');
        }

        $active = $root . DIRECTORY_SEPARATOR . $generation;
        if (!is_dir($active) || is_link($active)) {
            throw new \RuntimeException('ArrayKit namespace cache generation is not a trusted directory.');
        }

        return $active;
    }

    private function assertNativeGenerationsSafe(string $directory): void
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $pointer = $root . DIRECTORY_SEPARATOR . '.arraykit-generation';
        if (is_link($pointer)) {
            throw new \RuntimeException('ArrayKit generation pointer cannot be a symbolic link.');
        }
        if (is_file($pointer)) {
            $this->activeCacheDirectory($directory);
        }

        foreach (glob($root . DIRECTORY_SEPARATOR . '.arraykit-gen-*') ?: [] as $generation) {
            if (is_link($generation) || !is_dir($generation)) {
                throw new \RuntimeException('ArrayKit cache generation cannot be a symbolic link or non-directory.');
            }

            $entries = scandir($generation);
            if ($entries === false) {
                throw new \RuntimeException('Unable to inspect ArrayKit namespace cache generation.');
            }
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $path = $generation . DIRECTORY_SEPARATOR . $entry;
                if (is_link($path) || !is_file($path) || !str_ends_with($entry, '.php')) {
                    throw new \RuntimeException('ArrayKit generation contains an unsafe file or symbolic link.');
                }
            }
        }
    }

    /**
     * Existing readers may be pinned to an older ArrayKit generation. Never
     * delete those directories during an in-place cache mode transition.
     * Release-owned generation garbage collection follows the host's lease.
     */
    private function retireNativeGenerationPointer(string $directory): void
    {
        $pointer = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.arraykit-generation';
        if (is_file($pointer) && !unlink($pointer)) {
            throw new \RuntimeException('Unable to retire obsolete ArrayKit generation pointer.');
        }
    }

    private function assertPublicationRoot(string $directory): void
    {
        if (is_link($directory)) {
            throw new \RuntimeException('ArrayKit configuration cache publication root cannot be a symbolic link.');
        }
    }

    /** @param list<string> $namespaces */
    private function removeStaleFiles(string $directory, array $namespaces, bool $keepFlat = true): void
    {
        $keep = array_fill_keys([
            ...$namespaces,
            ...($keepFlat ? ['__flat'] : []),
            '__manifest',
        ], true);

        foreach (glob(rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            if (!isset($keep[pathinfo($file, PATHINFO_FILENAME)]) && !unlink($file)) {
                throw new \RuntimeException(sprintf(
                    'Unable to remove stale config cache artifact "%s".',
                    $file,
                ));
            }
        }
    }

    private function validateNamespace(string $directory, string $namespace): void
    {
        $file = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $namespace . '.php';
        if (!is_file($file) || !chmod($file, 0664)) {
            throw new \RuntimeException(sprintf(
                'Unable to finalize ArrayKit namespace config cache "%s".',
                $file,
            ));
        }

        try {
            $materialized = require $file;
        } catch (\Throwable $exception) {
            throw new \RuntimeException(sprintf(
                'Unable to validate ArrayKit namespace config cache "%s".',
                $file,
            ), previous: $exception);
        }
        if (!is_array($materialized)) {
            throw new \RuntimeException(sprintf(
                'ArrayKit namespace config cache "%s" did not return an array.',
                $file,
            ));
        }

        ConfigExportValidator::assertExportable([$namespace => $materialized]);
    }
}
