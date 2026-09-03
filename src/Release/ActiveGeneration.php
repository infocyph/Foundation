<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

final class ActiveGeneration
{
    private const int FORMAT = 1;
    private const string POINTER = 'active.php';

    /** @return array{generation:string,manifest:string} */
    public function current(string $releaseRoot): array
    {
        $releaseRoot = $this->root($releaseRoot);
        $pointerPath = $releaseRoot . DIRECTORY_SEPARATOR . self::POINTER;
        if (!is_file($pointerPath) || !is_readable($pointerPath)) {
            throw new \RuntimeException('Foundation active-generation pointer is unavailable.');
        }

        $pointer = require $pointerPath;
        if (!is_array($pointer) || ($pointer['format'] ?? null) !== self::FORMAT) {
            throw new \UnexpectedValueException('Foundation active-generation pointer is invalid.');
        }
        $generation = $pointer['generation'] ?? null;
        $manifest = $pointer['manifest'] ?? null;
        if (!is_string($generation)
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1
            || !is_string($manifest)
            || $manifest !== 'generations/' . $generation . '/foundation.php'
        ) {
            throw new \UnexpectedValueException('Foundation active-generation pointer identity is invalid.');
        }

        $manifestPath = $releaseRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $manifest);
        $release = FoundationReleaseManifest::load($manifestPath);
        if (($release['generation'] ?? null) !== $generation) {
            throw new \RuntimeException('Foundation active-generation pointer does not match its release manifest.');
        }

        return ['generation' => $generation, 'manifest' => $manifestPath];
    }

    public function activate(string $releaseRoot, string $generation): string
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1) {
            throw new \InvalidArgumentException('Foundation generation identifier is invalid.');
        }

        $releaseRoot = $this->root($releaseRoot);
        $relativeManifest = 'generations/' . $generation . '/foundation.php';
        $manifestPath = $releaseRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeManifest);
        $manifest = FoundationReleaseManifest::load($manifestPath);
        if (($manifest['generation'] ?? null) !== $generation) {
            throw new \RuntimeException('Foundation generation manifest identity does not match activation target.');
        }

        if (!is_dir($releaseRoot) && !mkdir($releaseRoot, 0775, true) && !is_dir($releaseRoot)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release root "%s".', $releaseRoot));
        }
        $pointerPath = $releaseRoot . DIRECTORY_SEPARATOR . self::POINTER;
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export([
            'format' => self::FORMAT,
            'generation' => $generation,
            'manifest' => $relativeManifest,
        ], true) . ";\n";
        $temporary = tempnam($releaseRoot, '.foundation-active-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to stage Foundation active-generation pointer.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, 0644)
                || !rename($temporary, $pointerPath)
            ) {
                throw new \RuntimeException('Unable to atomically activate Foundation release generation.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $pointerPath;
    }

    public function replacementRequired(string $releaseRoot, string $loadedGeneration): bool
    {
        return !hash_equals($loadedGeneration, $this->current($releaseRoot)['generation']);
    }

    private function root(string $releaseRoot): string
    {
        $releaseRoot = rtrim($releaseRoot, DIRECTORY_SEPARATOR);
        if ($releaseRoot === '') {
            throw new \InvalidArgumentException('Foundation release root must not be empty.');
        }

        return $releaseRoot;
    }
}
