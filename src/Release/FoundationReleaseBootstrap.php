<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Application\RuntimeMode;

/** External deployment input selecting one trusted active Foundation generation. */
final readonly class FoundationReleaseBootstrap
{
    public const string MANIFEST_SHA256_ENV = 'INFOCYPH_FOUNDATION_RELEASE_MANIFEST_SHA256';

    public const string RELEASE_ROOT_ENV = 'INFOCYPH_FOUNDATION_RELEASE_ROOT';

    public function __construct(
        public string $releaseRoot,
        public string $trustedFoundationManifestSha256,
    ) {
        if ($releaseRoot === '') {
            throw new \InvalidArgumentException('Foundation release root must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', strtolower(trim($trustedFoundationManifestSha256))) !== 1) {
            throw new \InvalidArgumentException('Trusted Foundation generation manifest SHA-256 is invalid.');
        }
    }

    /** @param array<string,mixed> $config */
    public static function fromEnvironment(array $config = []): ?self
    {
        $trustedSha256 = self::environment(self::MANIFEST_SHA256_ENV);
        if ($trustedSha256 === null) {
            return null;
        }

        $basePath = self::basePath($config);
        $releaseRoot = self::environment(self::RELEASE_ROOT_ENV)
            ?? $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'releases';
        if (!self::absolute($releaseRoot)) {
            $releaseRoot = $basePath . DIRECTORY_SEPARATOR . trim($releaseRoot, DIRECTORY_SEPARATOR);
        }

        return new self(
            rtrim($releaseRoot, DIRECTORY_SEPARATOR),
            strtolower(trim($trustedSha256)),
        );
    }

    /** @param array<string,mixed> $config */
    public function nonWeb(array $config, RuntimeMode $runtime): Application
    {
        return new FoundationReleaseRuntime()->nonWebPrevalidated(
            $config,
            $runtime,
            $this->releaseRoot,
            $this->trustedFoundationManifestSha256,
        )->application;
    }

    private static function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /** @param array<string,mixed> $config */
    private static function basePath(array $config): string
    {
        $app = is_array($config['app'] ?? null) ? $config['app'] : [];
        $basePath = $app['base_path'] ?? $config['base_path'] ?? null;
        if (!is_string($basePath) || $basePath === '') {
            $basePath = getcwd() ?: dirname(__DIR__, 2);
        }

        return rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    private static function environment(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
