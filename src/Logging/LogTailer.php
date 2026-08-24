<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

final readonly class LogTailer
{
    /** @return list<string> */
    public function tail(string $path, int $lines = 100): array
    {
        if ($lines < 1 || $lines > 10_000) {
            throw new \InvalidArgumentException('Log tail line count must be between 1 and 10000.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Log file "%s" is not readable.', $path));
        }

        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open log file "%s".', $path));
        }

        try {
            if (fseek($stream, 0, SEEK_END) !== 0) {
                throw new \RuntimeException(sprintf('Unable to seek log file "%s".', $path));
            }
            $position = ftell($stream);
            if (!is_int($position) || $position === 0) {
                return [];
            }

            $buffer = '';
            $chunkSize = 8192;
            while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
                $read = min($chunkSize, $position);
                $position -= $read;
                fseek($stream, $position);
                $chunk = fread($stream, $read);
                if (!is_string($chunk)) {
                    break;
                }
                $buffer = $chunk . $buffer;
            }

            $rows = preg_split('/\R/', rtrim($buffer, "\r\n"));
            if (!is_array($rows)) {
                return [];
            }

            return array_slice($rows, -$lines);
        } finally {
            fclose($stream);
        }
    }

    /** @param callable(string):void $consumer */
    public function follow(string $path, callable $consumer, int $sleepMicros = 250_000): void
    {
        if ($sleepMicros < 10_000 || $sleepMicros > 5_000_000) {
            throw new \InvalidArgumentException('Log follow sleep must be between 10000 and 5000000 microseconds.');
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new \RuntimeException(sprintf('Log file "%s" is not readable.', $path));
        }

        $stream = $this->open($path);
        fseek($stream, 0, SEEK_END);

        try {
            while (true) {
                $line = fgets($stream);
                if ($line !== false) {
                    $consumer(rtrim($line, "\r\n"));
                    continue;
                }

                clearstatcache(true, $path);
                $position = ftell($stream);
                $streamStat = fstat($stream);
                $pathStat = is_file($path) ? $this->statPath($path) : false;

                if (is_int($position) && is_array($streamStat) && is_array($pathStat)) {
                    $replaced = $this->identityChanged($streamStat, $pathStat);
                    $truncated = is_int($pathStat['size'] ?? null) && $pathStat['size'] < $position;
                    if ($replaced) {
                        fclose($stream);
                        $stream = $this->open($path);
                        continue;
                    }
                    if ($truncated) {
                        rewind($stream);
                        continue;
                    }
                }

                usleep($sleepMicros);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** @param array<string|int,mixed> $streamStat @param array<string|int,mixed> $pathStat */
    private function identityChanged(array $streamStat, array $pathStat): bool
    {
        $streamInode = $streamStat['ino'] ?? null;
        $pathInode = $pathStat['ino'] ?? null;
        $streamDevice = $streamStat['dev'] ?? null;
        $pathDevice = $pathStat['dev'] ?? null;

        return is_int($streamInode)
            && is_int($pathInode)
            && $streamInode !== 0
            && $pathInode !== 0
            && ($streamInode !== $pathInode || $streamDevice !== $pathDevice);
    }

    /** @return resource */
    private function open(string $path)
    {
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open log file "%s".', $path));
        }

        return $stream;
    }

    /** @return array<int|string, mixed>|false */
    private function statPath(string $path): array|false
    {
        set_error_handler(static fn(int $severity): bool => $severity === E_WARNING);
        try {
            return stat($path);
        } finally {
            restore_error_handler();
        }
    }
}
