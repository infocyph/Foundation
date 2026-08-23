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

        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open log file "%s".', $path));
        }
        fseek($stream, 0, SEEK_END);

        try {
            while (true) {
                $line = fgets($stream);
                if ($line !== false) {
                    $consumer(rtrim($line, "\r\n"));
                    continue;
                }
                clearstatcache(true, $path);
                usleep($sleepMicros);
            }
        } finally {
            fclose($stream);
        }
    }
}
