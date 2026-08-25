<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Logging;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

final class JsonLogger extends AbstractLogger
{
    /** @var array<string, int> */
    private const array LEVELS = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    /**
     * @param list<string> $redactedKeys
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $minimumLevel,
        private readonly ?string $path = null,
        private readonly array $redactedKeys = [],
        private readonly bool $includeExceptionMessage = false,
        private readonly bool $includeExceptionTrace = false,
    ) {
        if (!isset(self::LEVELS[$minimumLevel])) {
            throw new \InvalidArgumentException(sprintf('Unsupported logging level "%s".', $minimumLevel));
        }
        if (!in_array($driver, ['error_log', 'file'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported JSON logger driver "%s".', $driver));
        }
        if ($driver === 'file' && ($path === null || $path === '')) {
            throw new \InvalidArgumentException('The file logger requires a non-empty path.');
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $level = is_string($level) ? strtolower($level) : '';
        if (!isset(self::LEVELS[$level])) {
            throw new \Psr\Log\InvalidArgumentException(sprintf('Unsupported logging level "%s".', $level));
        }
        if (self::LEVELS[$level] < self::LEVELS[$this->minimumLevel]) {
            return;
        }

        $record = json_encode([
            'timestamp' => self::timestamp(),
            'level' => $level,
            'message' => (string) $message,
            'context' => $this->normalize($context),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        $line = ($record === false ? '{"level":"error","message":"Unable to encode log record"}' : $record) . PHP_EOL;

        if ($this->driver === 'error_log') {
            error_log(rtrim($line, PHP_EOL));

            return;
        }

        $path = $this->path ?? '';
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create log directory "%s".', $directory));
        }
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to append log file "%s".', $path));
        }
    }

    private static function timestamp(): string
    {
        [$fraction, $seconds] = explode(' ', microtime());

        return gmdate('Y-m-d\TH:i:s', (int) $seconds)
            . substr($fraction, 1, 7)
            . 'Z';
    }

    private function isSensitive(string $key): bool
    {
        $key = strtolower($key);

        return array_any(
            $this->redactedKeys,
            static fn(string $sensitive): bool => $sensitive !== ''
                && str_contains($key, strtolower($sensitive)),
        );
    }

    private function normalize(mixed $value, int $depth = 0, ?string $key = null): mixed
    {
        if ($key !== null && $this->isSensitive($key)) {
            return '[REDACTED]';
        }
        if ($depth >= 5) {
            return '[DEPTH_LIMIT]';
        }
        if ($value instanceof \Throwable) {
            return $this->normalizeException($value);
        }
        if (is_array($value)) {
            return $this->normalizeArray($value, $depth);
        }
        if (is_object($value)) {
            return ['class' => $value::class];
        }
        if (is_resource($value)) {
            return '[RESOURCE]';
        }

        return $value;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<array-key, mixed>
     */
    private function normalizeArray(array $values, int $depth): array
    {
        $normalized = [];
        foreach ($values as $itemKey => $item) {
            $normalized[$itemKey] = $this->normalize(
                $item,
                $depth + 1,
                is_string($itemKey) ? $itemKey : null,
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, int|string>
     */
    private function normalizeException(\Throwable $exception): array
    {
        $normalized = [
            'class' => $exception::class,
            'code' => $exception->getCode(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ];
        if ($this->includeExceptionMessage) {
            $normalized['message'] = $exception->getMessage();
        }
        if ($this->includeExceptionTrace) {
            $normalized['trace'] = $exception->getTraceAsString();
        }

        return $normalized;
    }
}
