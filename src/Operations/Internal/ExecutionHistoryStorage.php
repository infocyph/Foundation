<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations\Internal;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Support\ValueNormalizer;

final readonly class ExecutionHistoryStorage
{
    private const int DEFAULT_RETAINED_FILES = 7;

    public function __construct(private Application $application) {}

    /** @return list<string> */
    public function filesOldestFirst(): array
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

    /** @return iterable<array<string,mixed>> */
    public function records(string $path): iterable
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

    private function path(): string
    {
        $configured = $this->application->config()->getString(
            'operations.history.path',
            'storage/logs/executions.jsonl',
        ) ?? 'storage/logs/executions.jsonl';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? $configured
            : $this->application->basePath(trim($configured, DIRECTORY_SEPARATOR));
    }

    private function retainedFiles(): int
    {
        $value = $this->application->config()->getInt('operations.history.retained_files', self::DEFAULT_RETAINED_FILES);

        return is_int($value) && $value >= 0 ? min(100, $value) : self::DEFAULT_RETAINED_FILES;
    }
}
