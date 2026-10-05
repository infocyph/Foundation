<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Config;

use Infocyph\ArrayKit\Config\Config;
use Infocyph\ArrayKit\Config\LazyFileConfig;

final class ArrayKitConfigCache
{
    public const string SINGLE_FILE = 'config.php';

    private const string ARRAYKIT_FLAT_INDEX_FILE = '.arraykit-flat.php';

    private const string ARRAYKIT_GENERATION_POINTER = '.arraykit-generation';

    private const string ARRAYKIT_GENERATION_PREFIX = '.arraykit-gen-';

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
        $namespaces = [];
        foreach ($config as $namespace => $value) {
            if (is_array($value) && preg_match('/^[A-Za-z0-9_-]+$/', $namespace) === 1) {
                $namespaces[] = $namespace;
            }
        }
        sort($namespaces);
        $this->removeStaleFiles($directory, $namespaces);

        new LazyFileConfig(
            directory: $directory,
            items: array_intersect_key($config, array_fill_keys($namespaces, true)),
            namespaceCacheDirectory: $directory,
        )->warmNamespaceCache($namespaces);

        $activeDirectory = $this->activeNamespaceCacheDirectory($directory);
        foreach ($namespaces as $namespace) {
            $this->validateNamespace($activeDirectory, $namespace);
        }
        $this->finalizeNativeMetadata($directory, $activeDirectory);

        return $namespaces;
    }

    /** @param array<string,mixed> $config */
    public function writeSingle(array $config, string $directory): void
    {
        $this->removeStaleFiles($directory, [], keepFlat: false);
        $this->removeNativeGenerationArtifacts($directory);

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
    }

    private function activeNamespaceCacheDirectory(string $directory): string
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $pointer = $root . DIRECTORY_SEPARATOR . self::ARRAYKIT_GENERATION_POINTER;
        if (!is_file($pointer) || !is_readable($pointer)) {
            return $root;
        }

        $generation = trim((string) file_get_contents($pointer));
        if (preg_match('/^\.arraykit-gen-[a-f0-9]+$/', $generation) !== 1) {
            throw new \RuntimeException(sprintf(
                'ArrayKit namespace cache generation pointer "%s" is invalid.',
                $pointer,
            ));
        }

        $active = $root . DIRECTORY_SEPARATOR . $generation;
        if (!is_dir($active)) {
            throw new \RuntimeException(sprintf(
                'ArrayKit namespace cache generation "%s" is missing.',
                $active,
            ));
        }

        return $active;
    }

    private function finalizeNativeMetadata(string $directory, string $activeDirectory): void
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $pointer = $root . DIRECTORY_SEPARATOR . self::ARRAYKIT_GENERATION_POINTER;
        if (is_file($pointer) && !chmod($pointer, 0664)) {
            throw new \RuntimeException(sprintf(
                'Unable to finalize ArrayKit namespace cache generation pointer "%s".',
                $pointer,
            ));
        }

        $flat = $activeDirectory . DIRECTORY_SEPARATOR . self::ARRAYKIT_FLAT_INDEX_FILE;
        if (is_file($flat) && !chmod($flat, 0664)) {
            throw new \RuntimeException(sprintf(
                'Unable to finalize ArrayKit namespace flat index "%s".',
                $flat,
            ));
        }

        $legacyFlat = $root . DIRECTORY_SEPARATOR . '__flat.php';
        if ($activeDirectory === $root && is_file($legacyFlat) && !chmod($legacyFlat, 0664)) {
            throw new \RuntimeException(sprintf(
                'Unable to finalize legacy ArrayKit namespace flat index "%s".',
                $legacyFlat,
            ));
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

    private function removeNativeGenerationArtifacts(string $directory): void
    {
        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $pointer = $root . DIRECTORY_SEPARATOR . self::ARRAYKIT_GENERATION_POINTER;
        if (is_file($pointer) && !unlink($pointer)) {
            throw new \RuntimeException(sprintf(
                'Unable to remove stale ArrayKit namespace generation pointer "%s".',
                $pointer,
            ));
        }

        foreach (glob($root . DIRECTORY_SEPARATOR . self::ARRAYKIT_GENERATION_PREFIX . '*', GLOB_ONLYDIR) ?: [] as $generation) {
            $this->removeDirectory($generation);
        }
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            if (!unlink($path)) {
                throw new \RuntimeException(sprintf(
                    'Unable to remove stale ArrayKit namespace cache artifact "%s".',
                    $path,
                ));
            }
        }

        if (!rmdir($directory)) {
            throw new \RuntimeException(sprintf(
                'Unable to remove stale ArrayKit namespace cache generation "%s".',
                $directory,
            ));
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
