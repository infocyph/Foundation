<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

final class FoundationReleaseManifest
{
    public const int FORMAT = 1;

    /** @param array<string,mixed> $manifest */
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

    /** @return array<string,mixed> */
    public static function load(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Foundation release manifest is not readable: "%s".', $path));
        }

        $loaded = require $path;
        if (!is_array($loaded)) {
            throw new \UnexpectedValueException('Foundation release manifest must return an array.');
        }
        $manifest = self::stringMap($loaded, 'manifest');
        self::assertValid($manifest);

        return $manifest;
    }

    /** @param array<string,mixed> $manifest */
    public static function assertValid(array $manifest): void
    {
        if (($manifest['format'] ?? null) !== self::FORMAT) {
            throw new \UnexpectedValueException('Unsupported Foundation release manifest format.');
        }
        self::identifier($manifest['generation'] ?? null, 'generation');
        self::nonEmptyString($manifest['environment'] ?? null, 'environment');
        self::digest($manifest['config_fingerprint'] ?? null, 64, 'config_fingerprint');

        $web = self::section($manifest, 'web');
        self::relativePath($web['release_manifest'] ?? null, 'web.release_manifest');
        self::digest($web['runtime_manifest_sha256'] ?? null, 64, 'web.runtime_manifest_sha256');
        self::capabilities($web['capabilities'] ?? null, 'web.capabilities');

        foreach (['cli', 'worker', 'scheduler'] as $runtime) {
            $section = self::section($manifest, $runtime);
            self::relativePath($section['intermix_path'] ?? null, $runtime . '.intermix_path');
            self::digest($section['digest'] ?? null, 32, $runtime . '.digest');
            self::relativePath($section['metadata_path'] ?? null, $runtime . '.metadata_path');
            self::digest($section['metadata_sha256'] ?? null, 64, $runtime . '.metadata_sha256');
            self::capabilities($section['capabilities'] ?? null, $runtime . '.capabilities');
        }
    }

    /** @return array<int|string,mixed> */
    public static function capabilities(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    public static function digest(mixed $value, int $length, string $field): string
    {
        if (!is_string($value) || preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

    public static function nonEmptyString(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
    }

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
     */
    public static function section(array $manifest, string $name): array
    {
        $section = $manifest[$name] ?? null;
        if (!is_array($section)) {
            throw new \UnexpectedValueException(sprintf('Foundation release section "%s" is invalid.', $name));
        }

        return self::stringMap($section, $name);
    }

    private static function identifier(mixed $value, string $field): string
    {
        $value = self::nonEmptyString($value, $field);
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $value) !== 1) {
            throw new \UnexpectedValueException(sprintf('Foundation release field "%s" is invalid.', $field));
        }

        return $value;
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
}
