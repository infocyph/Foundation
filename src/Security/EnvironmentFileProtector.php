<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Security;

use Infocyph\Epicrypt\DataProtection\FileProtector;
use Infocyph\Epicrypt\DataProtection\ProtectionOptions;
use Infocyph\Foundation\Application\Application;

final readonly class EnvironmentFileProtector
{
    private const string PURPOSE = 'foundation.environment';

    private const string AAD = 'environment-file/v1';

    public function __construct(private Application $application) {}

    public function decrypt(
        ?string $input = null,
        ?string $output = null,
        ?string $keyFile = null,
        string $keyEnvironment = 'ENV_ENCRYPTION_KEY',
        bool $force = false,
    ): string {
        $this->assertAvailable();
        $source = $this->path($input ?? '.env.encrypted');
        $target = $this->path($output ?? '.env');
        $this->assertSource($source);
        $this->assertTarget($target, $force);

        new FileProtector()->unprotect(
            $source,
            $target,
            $this->key($keyFile, $keyEnvironment),
            new ProtectionOptions(self::PURPOSE, self::AAD),
        );

        @chmod($target, 0600);

        return $target;
    }

    public function encrypt(
        ?string $input = null,
        ?string $output = null,
        ?string $keyFile = null,
        string $keyEnvironment = 'ENV_ENCRYPTION_KEY',
        bool $force = false,
    ): string {
        $this->assertAvailable();
        $source = $this->path($input ?? '.env');
        $target = $this->path($output ?? '.env.encrypted');
        $this->assertSource($source);
        $this->assertTarget($target, $force);

        new FileProtector()->protect(
            $source,
            $target,
            $this->key($keyFile, $keyEnvironment),
            new ProtectionOptions(self::PURPOSE, self::AAD),
        );

        @chmod($target, 0600);

        return $target;
    }

    private function assertAvailable(): void
    {
        if (!class_exists(FileProtector::class)) {
            throw new \LogicException(
                'Environment encryption requires the security module; run "php infbyte module:install security".',
            );
        }
    }

    private function assertSource(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Environment source "%s" is not a readable file.', $path));
        }
    }

    private function assertTarget(string $path, bool $force): void
    {
        if ((is_file($path) || is_link($path)) && !$force) {
            throw new \RuntimeException(sprintf('Environment target "%s" already exists; use --force to replace it.', $path));
        }
        if (is_link($path)) {
            throw new \RuntimeException('Environment protection refuses to replace symbolic-link targets.');
        }
        if (is_file($path) && $force && !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to replace environment target "%s".', $path));
        }
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create environment target directory "%s".', $directory));
        }
    }

    private function key(?string $keyFile, string $keyEnvironment): string
    {
        if ($keyFile !== null && $keyFile !== '') {
            $path = $this->path($keyFile);
            $value = is_file($path) ? file_get_contents($path) : false;
            if (!is_string($value)) {
                throw new \RuntimeException(sprintf('Unable to read environment encryption key file "%s".', $path));
            }
            $key = trim($value);
        } else {
            $value = getenv($keyEnvironment);
            $key = is_string($value) ? trim($value) : '';
        }
        if ($key === '') {
            throw new \RuntimeException(sprintf(
                'Environment encryption key is unavailable. Set %s or pass --key-file.',
                $keyEnvironment,
            ));
        }

        return $key;
    }

    private function path(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new \InvalidArgumentException('Environment file path must be non-empty and NUL-free.');
        }

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? $path
            : $this->application->basePath(trim($path, DIRECTORY_SEPARATOR));
    }
}
