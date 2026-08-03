<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Foundation\Application\Application;

final readonly class EnvironmentSecretManager
{
    private const string VARIABLE = 'AUTH_TOKEN_SECRET';

    public function __construct(
        private Application $application,
        private ConfigCacheManager $configCache,
    ) {}

    public function generate(bool $force = false): string
    {
        $path = $this->application->basePath('.env');
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            throw new \RuntimeException(sprintf('Unable to read environment file "%s".', $path));
        }

        $pattern = '/^' . self::VARIABLE . '=(.*)$/m';
        $occurrences = preg_match_all($pattern, $contents, $matches);
        if ($occurrences === false) {
            throw new \RuntimeException('Unable to inspect the authentication token secret.');
        }
        if ($occurrences > 1) {
            throw new \RuntimeException(self::VARIABLE . ' must be declared only once in .env.');
        }
        $exists = $occurrences === 1;
        $current = $exists ? trim($matches[1][0], " \t\r\n\"'") : '';
        if ($current !== '' && !$force) {
            throw new \RuntimeException(self::VARIABLE . ' already exists; use --force to rotate it.');
        }

        $line = self::VARIABLE . '=' . bin2hex(random_bytes(32));
        if ($exists) {
            $updated = preg_replace($pattern, $line, $contents, 1, $replacements);
            if (!is_string($updated) || $replacements !== 1) {
                throw new \RuntimeException('Unable to replace the authentication token secret.');
            }
        } else {
            $updated = rtrim($contents, "\r\n") . PHP_EOL . $line . PHP_EOL;
        }

        $this->configCache->clear('bootstrap/cache/config');
        $this->write($path, $updated);

        return $path;
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $handle = fopen($temporary, 'x');
        if (!is_resource($handle)) {
            throw new \RuntimeException(sprintf('Unable to create temporary environment file "%s".', $temporary));
        }

        try {
            $length = strlen($contents);
            $written = 0;
            while ($written < $length) {
                $bytes = fwrite($handle, substr($contents, $written));
                if ($bytes === false || $bytes === 0) {
                    throw new \RuntimeException('Unable to write the complete environment file.');
                }
                $written += $bytes;
            }
            if (!fflush($handle) || !chmod($temporary, 0600)) {
                throw new \RuntimeException('Unable to secure the generated environment file.');
            }
            fclose($handle);
            $handle = null;

            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to activate environment file "%s".', $path));
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
