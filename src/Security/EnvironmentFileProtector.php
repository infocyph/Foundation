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
        return $this->transform(
            protect: false,
            input: $input ?? '.env.encrypted',
            output: $output ?? '.env',
            keyFile: $keyFile,
            keyEnvironment: $keyEnvironment,
            force: $force,
        );
    }

    public function encrypt(
        ?string $input = null,
        ?string $output = null,
        ?string $keyFile = null,
        string $keyEnvironment = 'ENV_ENCRYPTION_KEY',
        bool $force = false,
    ): string {
        return $this->transform(
            protect: true,
            input: $input ?? '.env',
            output: $output ?? '.env.encrypted',
            keyFile: $keyFile,
            keyEnvironment: $keyEnvironment,
            force: $force,
        );
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

    private function prepareTarget(string $path, bool $force): void
    {
        if (is_link($path)) {
            throw new \RuntimeException('Environment protection refuses to replace symbolic-link targets.');
        }
        if (file_exists($path) && !is_file($path)) {
            throw new \RuntimeException(sprintf('Environment target "%s" must be a regular file path.', $path));
        }
        if (is_file($path) && !$force) {
            throw new \RuntimeException(sprintf('Environment target "%s" already exists; use --force to replace it.', $path));
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

    private function transform(
        bool $protect,
        string $input,
        string $output,
        ?string $keyFile,
        string $keyEnvironment,
        bool $force,
    ): string {
        $this->assertAvailable();
        $source = $this->path($input);
        $target = $this->path($output);
        $this->assertSource($source);
        $this->prepareTarget($target, $force);

        $temporary = $target . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $backup = null;
        $protector = new FileProtector();
        $options = new ProtectionOptions(self::PURPOSE, self::AAD);
        $key = $this->key($keyFile, $keyEnvironment);

        try {
            if ($protect) {
                $protector->protect($source, $temporary, $key, $options);
            } else {
                $protector->unprotect($source, $temporary, $key, $options);
            }
            @chmod($temporary, 0600);

            if (is_file($target)) {
                $backup = $target . '.' . bin2hex(random_bytes(8)) . '.bak';
                if (!rename($target, $backup)) {
                    throw new \RuntimeException(sprintf('Unable to stage existing environment target "%s".', $target));
                }
            }
            if (!rename($temporary, $target)) {
                throw new \RuntimeException(sprintf('Unable to publish environment target "%s".', $target));
            }

            if ($backup !== null && is_file($backup) && !unlink($backup)) {
                $restored = is_file($target) && unlink($target) && rename($backup, $target);
                throw new \RuntimeException($restored
                    ? sprintf('Unable to finalize environment target "%s"; the previous file was restored.', $target)
                    : sprintf('Unable to remove environment target backup "%s"; manual recovery may be required.', $backup));
            }

            return $target;
        } catch (\Throwable $failure) {
            if (is_file($temporary)) {
                unlink($temporary);
            }
            if ($backup !== null && is_file($backup) && !is_file($target)) {
                rename($backup, $target);
            }

            throw $failure;
        }
    }
}
