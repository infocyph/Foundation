<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Config\ConfigExportValidator;
use Infocyph\Foundation\Config\ConfigRepository;

/** Immutable, trusted config snapshot owned by one Foundation release generation. */
final class FoundationReleaseConfig
{
    private const int FORMAT = 1;

    /** @param array<string, mixed> $config */
    public static function write(string $path, array $config): string
    {
        ConfigExportValidator::assertExportable($config);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release config directory "%s".', $directory));
        }

        $payload = [
            'format' => self::FORMAT,
            'config' => $config,
        ];
        $contents = "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($payload, true) . ";\n";
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, 0640)
                || !rename($temporary, $path)
            ) {
                throw new \RuntimeException(sprintf('Unable to publish Foundation release config "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        $sha256 = hash_file('sha256', $path);
        if (!is_string($sha256)) {
            throw new \RuntimeException('Unable to fingerprint Foundation release config.');
        }

        return $sha256;
    }

    public static function load(string $path, string $trustedSha256): ConfigRepository
    {
        $trustedSha256 = FoundationReleaseManifest::digest($trustedSha256, 64, 'config_sha256');
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Foundation release config is not readable: "%s".', $path));
        }

        $actualSha256 = hash_file('sha256', $path);
        if (!is_string($actualSha256) || !hash_equals($trustedSha256, $actualSha256)) {
            throw new \RuntimeException('Foundation release config trust identity mismatch.');
        }

        $payload = require $path;
        if (!is_array($payload)
            || ($payload['format'] ?? null) !== self::FORMAT
            || !is_array($payload['config'] ?? null)
        ) {
            throw new \UnexpectedValueException('Foundation release config snapshot is malformed.');
        }

        /** @var array<string, mixed> $config */
        $config = $payload['config'];

        return new ConfigRepository($config, compiled: true);
    }
}
