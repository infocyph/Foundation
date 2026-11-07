<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Composer\InstalledVersions;
use Infocyph\ArrayKit\Config\Config;

final class FoundationReleaseManifest
{
    public const int FORMAT = 2;

    /**
     * @param array<string,mixed> $manifest
     * @throws \UnexpectedValueException
     */
    public static function assertValid(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new \UnexpectedValueException('Unsupported Foundation release manifest format.');
        }
        self::identifier($manifest['generation'] ?? null, 'generation');
        self::nonEmptyString($manifest['environment'] ?? null, 'environment');
        self::digest($manifest['config_fingerprint'] ?? null, 32, 'config_fingerprint');
        self::digest($manifest['dependency_fingerprint'] ?? null, 32, 'dependency_fingerprint');
        self::relativePath($manifest['config_path'] ?? null, 'config_path');
        self::digest($manifest['config_sha256'] ?? null, 64, 'config_sha256');

        $web = self::section($manifest, 'web');
        self::relativePath($web['release_manifest'] ?? null, 'web.release_manifest');
        self::digest($web['runtime_manifest_sha256'] ?? null, 64, 'web.runtime_manifest_sha256');
        self::matcherCache($web);
        self::capabilities($web['capabilities'] ?? null, 'web.capabilities');

        foreach (['cli', 'worker', 'scheduler'] as $runtime) {
            $section = self::section($manifest, $runtime);
            self::relativePath($section['intermix_path'] ?? null, $runtime . '.intermix_path');
            self::digest($section['digest'] ?? null, 32, $runtime . '.digest');
            self::relativePath($section['metadata_path'] ?? null, $runtime . '.metadata_path');
            self::digest($section['metadata_sha256'] ?? null, 64, $runtime . '.metadata_sha256');
            self::capabilities($section['capabilities'] ?? null, $runtime . '.capabilities');

            if ($runtime === 'worker') {
                self::workerTopology($section);
            }
        }
    }

    /**
     * @return array<int|string,mixed>
     * @throws \UnexpectedValueException
     */
    public static function capabilities(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    public static function dependencyFingerprint(): string
    {
        $packages = InstalledVersions::getInstalledPackages();
        sort($packages, SORT_STRING);
        $identity = [];

        foreach ($packages as $package) {
            $identity[$package] = [
                'version' => InstalledVersions::getVersion($package),
                'reference' => InstalledVersions::getReference($package),
            ];
        }

        return hash(
            'xxh128',
            json_encode($identity, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @throws \UnexpectedValueException
     */
    public static function digest(mixed $value, int $length, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * @return array<string,mixed>
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Foundation release manifest is not readable: "%s".', $path));
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException('Unable to capture Foundation release manifest bytes.');
        }
        $loaded = self::evaluateCapturedBytes($contents);
        if (!is_array($loaded)) {
            throw new \UnexpectedValueException('Foundation release manifest must return an array.');
        }
        $manifest = self::stringMap($loaded, 'manifest');
        self::assertValid($manifest);

        return $manifest;
    }

    /**
     * Load only the authenticated bytes. The temporary private copy prevents
     * a path replacement between digest verification and PHP evaluation.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function loadTrusted(string $path, string $sha256): array
    {
        $manifest = self::loadTrustedArray($path, $sha256);
        self::assertValid($manifest);

        return $manifest;
    }

    /**
     * Evaluate only captured, authenticated PHP bytes in a private staging file.
     * Callers validate their own schema after evaluation.
     *
     * @return array<string,mixed>
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function loadTrustedArray(string $path, string $sha256, string $artifact = 'release artifact'): array
    {
        self::digest($sha256, 64, 'artifact_sha256');

        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);

        try {
            $contents = file_get_contents($path);
        } finally {
            restore_error_handler();
        }

        if (!is_string($contents) || !hash_equals($sha256, hash('sha256', $contents))) {
            throw new \RuntimeException(sprintf('Foundation %s trust identity mismatch.', $artifact));
        }

        $value = self::evaluateCapturedBytes($contents);
        if (!is_array($value)) {
            throw new \UnexpectedValueException('Authenticated Foundation release artifact must return an array.');
        }

        /** @var array<string,mixed> $value */
        return $value;
    }

    /**
     * @throws \UnexpectedValueException
     */
    public static function nonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * @throws \UnexpectedValueException
     */
    public static function relativePath(mixed $value, string $field): string
    {
        $value = self::nonEmptyString($value, $field);
        if (str_contains($value, "\0")
            || preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $value) === 1
            || preg_match('#(?:^|[\\\\/])\.\.(?:[\\\\/]|$)#', $value) === 1
        ) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" must be generation-relative.', $field));
        }

        return str_replace('\\', '/', $value);
    }

    /**
     * @param array<string,mixed> $manifest
     * @return array<string,mixed>
     * @throws \UnexpectedValueException
     */
    public static function section(array $manifest, string $name): array
    {
        $section = $manifest[$name] ?? null;
        if (!is_array($section)) {
            throw new \UnexpectedValueException(sprintf('Foundation release section "%s" is invalid.', $name));
        }

        return self::stringMap($section, $name);
    }

    /**
     * @param array<string,mixed> $manifest
     * @throws \RuntimeException
     */
    public static function write(string $path, array $manifest): string
    {
        self::assertValid($manifest);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release manifest directory "%s".', $directory));
        }

        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($manifest, true) . ";\n";
        $temporary = tempnam($directory, '.foundation-release-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Unable to stage Foundation release manifest in "%s".', $directory));
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, 0644)
                || !rename($temporary, $path)
            ) {
                throw new \RuntimeException(sprintf('Unable to publish Foundation release manifest "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $path;
    }

    /**
     * Execute exactly the bytes captured by the caller, without reopening the
     * original path. An independently trusted caller verifies the digest first.
     * @throws \RuntimeException
     * @throws \UnexpectedValueException
     */
    private static function evaluateCapturedBytes(string $contents): mixed
    {
        $temporary = tempnam(sys_get_temp_dir(), 'foundation-manifest-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to stage Foundation release manifest.');
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)) {
                throw new \RuntimeException('Unable to write captured Foundation release manifest.');
            }

            $source = new Config();

            try {
                if (!$source->loadFile($temporary)) {
                    throw new \RuntimeException('Unable to evaluate staged Foundation release manifest.');
                }
            } catch (\UnexpectedValueException $exception) {
                throw new \UnexpectedValueException(
                    'Captured Foundation release artifact must return an array.',
                    previous: $exception,
                );
            }

            return $source->all();
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /**
     * @throws \UnexpectedValueException
     */
    private static function identifier(mixed $value, string $field): string
    {
        $value = self::nonEmptyString($value, $field);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $web
     * @throws \UnexpectedValueException
     */
    private static function matcherCache(array $web): void
    {
        if (!array_key_exists('matcher_cache_path', $web)) {
            return;
        }

        self::relativePath($web['matcher_cache_path'], 'web.matcher_cache_path');
    }

    /**
     * @param array<array-key,mixed> $value
     * @return array<string,mixed>
     */
    private static function stringMap(array $value, string $field): array
    {
        $mapped = [];
        foreach ($value as $key => $entry) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException(sprintf('Foundation release field "%s" must use string keys.', $field));
            }
            $mapped[$key] = $entry;
        }

        return $mapped;
    }

    /**
     * @param array<string,mixed> $section
     * @throws \UnexpectedValueException
     */
    private static function workerTopology(array $section): void
    {
        $hasPath = array_key_exists('provider_topology', $section);
        $hasDigest = array_key_exists('provider_topology_sha256', $section);
        if (!$hasPath && !$hasDigest) {
            return;
        }
        if (!$hasPath || !$hasDigest) {
            throw new \UnexpectedValueException(
                'Foundation worker topology path and trust identity must be declared together.',
            );
        }

        self::relativePath($section['provider_topology'], 'worker.provider_topology');
        self::digest($section['provider_topology_sha256'], 64, 'worker.provider_topology_sha256');
    }
}
