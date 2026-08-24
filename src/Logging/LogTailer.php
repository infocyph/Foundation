<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

final readonly class LogTailer
{
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
            for (;;) {
                if ($this->consumeAvailableLine($stream, $consumer)) {
                    continue;
                }

                $stream = $this->refreshStream($stream, $path);
                usleep($sleepMicros);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

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

    /**
     * @param resource $stream
     * @param callable(string):void $consumer
     */
    private function consumeAvailableLine($stream, callable $consumer): bool
    {
        $line = fgets($stream);
        if ($line === false) {
            return false;
        }

        $consumer(rtrim($line, "\r\n"));

        return true;
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

    /**
     * @param resource $stream
     * @return resource
     */
    private function refreshStream($stream, string $path)
    {
        clearstatcache(true, $path);
        $position = ftell($stream);
        $streamStat = fstat($stream);
        $pathStat = is_file($path) ? $this->statPath($path) : false;
        if (!is_int($position) || !is_array($streamStat) || !is_array($pathStat)) {
            return $stream;
        }

        if ($this->identityChanged($streamStat, $pathStat)) {
            fclose($stream);

            return $this->open($path);
        }

        $size = $pathStat['size'] ?? null;
        if (is_int($size) && $size < $position) {
            rewind($stream);
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
