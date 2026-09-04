<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigExportValidator;
use JsonException;

/** @internal Foundation identity sidecar for one generated non-web InterMix artifact. */
final class GeneratedRuntimeMetadata
{
    private const int SCHEMA_VERSION = 1;

    /** @param array<string,mixed> $metadata */
    public static function assertMatches(
        string $artifactPath,
        array $metadata,
        NonWebGraphComposition $graph,
    ): void {
        $providers = $graph->providers->classes();
        sort($providers, SORT_STRING);

        if (($metadata['schema_version'] ?? null) !== self::SCHEMA_VERSION
            || ($metadata['runtime'] ?? null) !== $graph->context->runtimeMode->value
            || ($metadata['environment'] ?? null) !== $graph->context->environment
            || ($metadata['config_fingerprint'] ?? null) !== self::configFingerprint($graph)
            || ($metadata['capabilities'] ?? null) !== $graph->context->capabilities
            || ($metadata['providers'] ?? null) !== $providers
        ) {
            throw new \RuntimeException('Foundation generated runtime identity does not match the current build inputs.');
        }

        $digest = $metadata['intermix_digest'] ?? null;
        if (!is_string($digest) || preg_match('/^[a-f0-9]{32}$/D', $digest) !== 1) {
            throw new \UnexpectedValueException('Foundation generated runtime InterMix digest is invalid.');
        }

        $manifestPath = $artifactPath . '.meta.json';
        if (!is_file($manifestPath) || !is_readable($manifestPath)) {
            throw new \RuntimeException(sprintf('InterMix runtime manifest is not readable: "%s".', $manifestPath));
        }
        $contents = file_get_contents($manifestPath);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read InterMix runtime manifest: "%s".', $manifestPath));
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('InterMix runtime manifest is invalid JSON.', 0, $exception);
        }
        $manifestDigest = is_array($manifest) ? ($manifest['digest'] ?? null) : null;
        if (!is_string($manifestDigest) || !hash_equals($digest, $manifestDigest)) {
            throw new \RuntimeException('Foundation generated runtime metadata does not match the InterMix artifact manifest.');
        }
    }

    /**
     * @param array{compiled:list<string>,skipped:array<string,string>,digest:string} $report
     * @return array{
     *   schema_version:int,
     *   runtime:string,
     *   environment:?string,
     *   config_fingerprint:string,
     *   capabilities:array<string,bool>,
     *   providers:list<string>,
     *   intermix_digest:string,
     *   compiled_count:int
     * }
     */
    public static function create(NonWebGraphComposition $graph, array $report): array
    {
        $providers = $graph->providers->classes();
        sort($providers, SORT_STRING);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'runtime' => $graph->context->runtimeMode->value,
            'environment' => $graph->context->environment,
            'config_fingerprint' => self::configFingerprint($graph),
            'capabilities' => $graph->context->capabilities,
            'providers' => $providers,
            'intermix_digest' => $report['digest'],
            'compiled_count' => count($report['compiled']),
        ];
    }

    public static function path(string $artifactPath): string
    {
        return $artifactPath . '.foundation.json';
    }

    /** @return array<string,mixed> */
    public static function read(string $artifactPath): array
    {
        $path = self::path($artifactPath);
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Foundation generated runtime metadata is not readable: "%s".', $path));
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read generated runtime metadata: "%s".', $path));
        }

        try {
            $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Foundation generated runtime metadata is invalid JSON.', 0, $exception);
        }
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Foundation generated runtime metadata must decode to an object.');
        }

        $metadata = [];
        foreach ($decoded as $key => $value) {
            if (!is_string($key)) {
                throw new \UnexpectedValueException('Foundation generated runtime metadata must use string keys.');
            }
            $metadata[$key] = $value;
        }

        return $metadata;
    }

    public static function resolvePath(Application $application, string $path): string
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Generated runtime artifact path must not be empty.');
        }
        if (preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return $application->basePath($path);
    }

    /** @param array<string,mixed> $metadata */
    public static function write(string $artifactPath, array $metadata): string
    {
        $path = self::path($artifactPath);
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create generated runtime metadata directory "%s".', $directory));
        }

        try {
            $contents = json_encode(
                $metadata,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . "\n";
        } catch (JsonException $exception) {
            throw new \RuntimeException('Unable to encode generated runtime metadata.', 0, $exception);
        }

        $temporary = tempnam($directory, '.foundation-runtime-');
        if ($temporary === false) {
            throw new \RuntimeException(sprintf('Unable to stage generated runtime metadata in "%s".', $directory));
        }

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
                || !chmod($temporary, 0644)
                || !rename($temporary, $path)
            ) {
                throw new \RuntimeException(sprintf('Unable to publish generated runtime metadata "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        return $path;
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $entry) {
            $value[$key] = self::canonicalize($entry);
        }

        return $value;
    }

    private static function configFingerprint(NonWebGraphComposition $graph): string
    {
        ConfigExportValidator::assertExportable($graph->context->config);

        return hash('sha256', serialize(self::canonicalize($graph->context->config)));
    }
}
