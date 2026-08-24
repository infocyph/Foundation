<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class ExecutionHistory
{
    private const int DEFAULT_MAX_BYTES = 16_777_216;

    private const int DEFAULT_RETAINED_FILES = 7;

    public function __construct(private Application $application) {}

    public function clear(): bool
    {
        return $this->withLock(true, function (): bool {
            $removed = false;
            foreach ($this->historyFilesOldestFirst() as $path) {
                if (is_file($path) && !unlink($path)) {
                    throw new \RuntimeException(sprintf('Unable to remove execution history "%s".', $path));
                }
                $removed = true;
            }

            return $removed;
        });
    }

    public function enabled(): bool
    {
        return $this->application->config()->getBool('operations.history.enabled', false) ?? false;
    }

    /** @return list<array<string,mixed>> */
    public function find(string $executionId): array
    {
        if ($executionId === '') {
            throw new \InvalidArgumentException('Execution id cannot be empty.');
        }

        return $this->withLock(false, function () use ($executionId): array {
            $records = [];
            foreach ($this->historyFilesOldestFirst() as $path) {
                foreach ($this->records($path) as $record) {
                    if (($record['execution_id'] ?? null) === $executionId) {
                        $records[] = $record;
                    }
                }
            }

            return $records;
        });
    }

    /** @return array<string,mixed>|null */
    public function latest(?string $kind = null, ?string $name = null): ?array
    {
        return $this->recent(1, $kind, $name)[0] ?? null;
    }

    /** @return array<string,mixed>|null */
    public function latestByMetadata(string $kind, string $key, string $value): ?array
    {
        if ($kind === '' || $key === '' || $value === '') {
            throw new \InvalidArgumentException('Execution history metadata lookup fields cannot be empty.');
        }

        return $this->withLock(false, function () use ($kind, $key, $value): ?array {
            $latest = null;
            foreach ($this->historyFilesOldestFirst() as $path) {
                foreach ($this->records($path) as $record) {
                    if (($record['kind'] ?? null) !== $kind) {
                        continue;
                    }
                    $metadata = $record['metadata'] ?? null;
                    if (is_array($metadata) && ($metadata[$key] ?? null) === $value) {
                        $latest = $record;
                    }
                }
            }

            return $latest;
        });
    }

    public function path(): string
    {
        $configured = $this->application->config()->getString(
            'operations.history.path',
            'storage/logs/executions.jsonl',
        ) ?? 'storage/logs/executions.jsonl';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? $configured
            : $this->application->basePath(trim($configured, DIRECTORY_SEPARATOR));
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 100, ?string $kind = null, ?string $name = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Execution history limit must be between 1 and 1000.');
        }

        return $this->withLock(false, function () use ($limit, $kind, $name): array {
            $records = [];
            foreach ($this->historyFilesOldestFirst() as $path) {
                foreach ($this->records($path) as $record) {
                    if (!$this->matches($record, $kind, $name)) {
                        continue;
                    }

                    $records[] = $record;
                    if (count($records) > $limit) {
                        array_shift($records);
                    }
                }
            }

            return array_reverse($records);
        });
    }

    /** @param array<string, scalar|null> $metadata */
    public function record(
        string $kind,
        string $executionId,
        string $name,
        string $status,
        ?int $exitCode = null,
        array $metadata = [],
    ): void {
        if (!$this->enabled()) {
            return;
        }
        foreach ([$kind, $executionId, $name, $status] as $value) {
            if ($value === '') {
                throw new \InvalidArgumentException('Execution history fields cannot be empty.');
            }
        }

        $payload = [
            'recorded_at' => microtime(true),
            'kind' => $kind,
            'execution_id' => $executionId,
            'name' => $name,
            'status' => $status,
            'exit_code' => $exitCode,
            'metadata' => $metadata,
        ];
        $line = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";

        $this->withLock(true, fn() => $this->append($line));
    }

    private function append(string $line): void
    {
        $path = $this->path();
        $size = is_file($path) ? filesize($path) : 0;
        if (is_int($size) && $size > 0 && $size + strlen($line) > $this->maxBytes()) {
            $this->rotate();
        }

        $stream = fopen($path, 'ab');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open execution history "%s".', $path));
        }

        try {
            if (fwrite($stream, $line) !== strlen($line) || !fflush($stream)) {
                throw new \RuntimeException(sprintf('Unable to append execution history "%s".', $path));
            }
        } finally {
            fclose($stream);
        }
    }

    /** @return list<string> */
    private function historyFilesOldestFirst(): array
    {
        $path = $this->path();
        $files = [];
        for ($index = $this->retainedFiles(); $index >= 1; --$index) {
            $rotated = $path . '.' . $index;
            if (is_file($rotated) && is_readable($rotated)) {
                $files[] = $rotated;
            }
        }
        if (is_file($path) && is_readable($path)) {
            $files[] = $path;
        }

        return $files;
    }

    /** @param array<string,mixed> $record */
    private function matches(array $record, ?string $kind, ?string $name): bool
    {
        return ($kind === null || ($record['kind'] ?? null) === $kind)
            && ($name === null || ($record['name'] ?? null) === $name);
    }

    private function maxBytes(): int
    {
        $value = $this->application->config()->getInt('operations.history.max_bytes', self::DEFAULT_MAX_BYTES);

        return is_int($value) && $value > 0 ? $value : self::DEFAULT_MAX_BYTES;
    }

    /** @return iterable<array<string,mixed>> */
    private function records(string $path): iterable
    {
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            return;
        }

        try {
            while (($line = fgets($stream)) !== false) {
                try {
                    $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (is_array($record)) {
                    yield ValueNormalizer::associativeArray($record);
                }
            }
        } finally {
            fclose($stream);
        }
    }

    private function retainedFiles(): int
    {
        $value = $this->application->config()->getInt('operations.history.retained_files', self::DEFAULT_RETAINED_FILES);

        return is_int($value) && $value >= 0 ? min(100, $value) : self::DEFAULT_RETAINED_FILES;
    }

    private function rotate(): void
    {
        $path = $this->path();
        $retained = $this->retainedFiles();
        if ($retained === 0) {
            $this->removeCurrentHistory($path);

            return;
        }

        $this->removeOldestHistory($path, $retained);
        $this->shiftRotatedHistory($path, $retained);
        if (is_file($path) && !rename($path, $path . '.1')) {
            throw new \RuntimeException(sprintf('Unable to rotate execution history "%s".', $path));
        }
    }

    private function removeCurrentHistory(string $path): void
    {
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to truncate execution history "%s".', $path));
        }
    }

    private function removeOldestHistory(string $path, int $retained): void
    {
        $oldest = $path . '.' . $retained;
        if (is_file($oldest) && !unlink($oldest)) {
            throw new \RuntimeException(sprintf('Unable to remove old execution history "%s".', $oldest));
        }
    }

    private function shiftRotatedHistory(string $path, int $retained): void
    {
        for ($index = $retained - 1; $index >= 1; --$index) {
            $source = $path . '.' . $index;
            if (is_file($source) && !rename($source, $path . '.' . ($index + 1))) {
                throw new \RuntimeException(sprintf('Unable to rotate execution history "%s".', $source));
            }
        }
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withLock(bool $exclusive, callable $callback): mixed
    {
        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create execution history directory "%s".', $directory));
        }

        $lock = fopen($path . '.lock', 'c+b');
        if (!is_resource($lock)) {
            throw new \RuntimeException(sprintf('Unable to open execution history lock "%s".', $path . '.lock'));
        }

        try {
            if (!flock($lock, $exclusive ? LOCK_EX : LOCK_SH)) {
                throw new \RuntimeException(sprintf('Unable to lock execution history "%s".', $path));
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
