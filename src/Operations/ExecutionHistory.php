<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Application\Application;

final readonly class ExecutionHistory
{
    public function __construct(private Application $application) {}

    public function enabled(): bool
    {
        return $this->application->config()->getBool('operations.history.enabled', false) ?? false;
    }

    /**
     * @param array<string, scalar|null> $metadata
     */
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

        $path = $this->path();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create execution history directory "%s".', $directory));
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

        $stream = fopen($path, 'ab');
        if (!is_resource($stream)) {
            throw new \RuntimeException(sprintf('Unable to open execution history "%s".', $path));
        }

        try {
            if (!flock($stream, LOCK_EX)) {
                throw new \RuntimeException(sprintf('Unable to lock execution history "%s".', $path));
            }
            if (fwrite($stream, $line) !== strlen($line) || !fflush($stream)) {
                throw new \RuntimeException(sprintf('Unable to append execution history "%s".', $path));
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }

    /**
     * Stream the history file and retain only the newest matching records.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100, ?string $kind = null, ?string $name = null): array
    {
        if ($limit < 1 || $limit > 1000) {
            throw new \InvalidArgumentException('Execution history limit must be between 1 and 1000.');
        }

        $path = $this->path();
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $records = [];
        $stream = fopen($path, 'rb');
        if (!is_resource($stream)) {
            return [];
        }

        try {
            while (($line = fgets($stream)) !== false) {
                try {
                    $record = json_decode($line, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }
                if (!is_array($record)) {
                    continue;
                }
                if ($kind !== null && ($record['kind'] ?? null) !== $kind) {
                    continue;
                }
                if ($name !== null && ($record['name'] ?? null) !== $name) {
                    continue;
                }

                $records[] = $record;
                if (count($records) > $limit) {
                    array_shift($records);
                }
            }
        } finally {
            fclose($stream);
        }

        return array_reverse($records);
    }

    public function clear(): bool
    {
        $path = $this->path();
        if (!is_file($path)) {
            return false;
        }

        return unlink($path);
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
}
