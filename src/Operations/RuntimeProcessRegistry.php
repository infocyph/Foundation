<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Application\Application;
use Infocyph\UID\Id;

final readonly class RuntimeProcessRegistry
{
    public function __construct(private Application $application) {}

    /** @return array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:?bool} */
    public function register(string $kind, string $name): array
    {
        $this->assertIdentity($kind, $name);
        $pid = getmypid();
        if (!is_int($pid) || $pid < 1) {
            throw new \RuntimeException('Unable to determine runtime process id.');
        }
        $record = [
            'id' => Id::uuid7(),
            'kind' => $kind,
            'name' => $name,
            'pid' => $pid,
            'started_at' => gmdate(DATE_ATOM),
            'heartbeat_at' => gmdate(DATE_ATOM),
            'host' => gethostname() ?: 'unknown',
        ];
        $this->write($record);

        return [...$record, 'running' => true];
    }

    /** @param array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string} $record */
    public function heartbeat(array $record): array
    {
        $record['heartbeat_at'] = gmdate(DATE_ATOM);
        $this->write($record);

        return [...$record, 'running' => true];
    }

    /** @return list<array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:?bool}> */
    public function all(?string $kind = null, ?string $name = null): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) {
            return [];
        }
        $records = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $record = $this->read($path);
            if ($record === null) {
                continue;
            }
            if ($kind !== null && $record['kind'] !== $kind) {
                continue;
            }
            if ($name !== null && $record['name'] !== $name) {
                continue;
            }
            $records[] = [...$record, 'running' => $this->running($record['pid'], $record['host'])];
        }

        usort($records, static fn(array $left, array $right): int => $right['started_at'] <=> $left['started_at']);

        return $records;
    }

    /** @param array{id:string} $record */
    public function unregister(array $record): void
    {
        $id = $record['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return;
        }
        $path = $this->directory() . DIRECTORY_SEPARATOR . $id . '.json';
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove runtime process record "%s".', $path));
        }
    }

    /** @param array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string} $record */
    private function write(array $record): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create runtime registry directory "%s".', $directory));
        }
        $path = $directory . DIRECTORY_SEPARATOR . $record['id'] . '.json';
        $temporary = $path . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $payload = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
        if (file_put_contents($temporary, $payload, LOCK_EX) === false) {
            throw new \RuntimeException(sprintf('Unable to stage runtime process record "%s".', $temporary));
        }
        try {
            if (!rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to activate runtime process record "%s".', $path));
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** @return array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string}|null */
    private function read(string $path): ?array
    {
        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }
        try {
            $record = json_decode($contents, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($record)
            || !is_string($record['id'] ?? null)
            || !is_string($record['kind'] ?? null)
            || !is_string($record['name'] ?? null)
            || !is_int($record['pid'] ?? null)
            || !is_string($record['started_at'] ?? null)
            || !is_string($record['heartbeat_at'] ?? null)
            || !is_string($record['host'] ?? null)
        ) {
            return null;
        }

        return $record;
    }

    private function running(int $pid, string $host): ?bool
    {
        if ($host !== (gethostname() ?: 'unknown')) {
            return null;
        }
        if (PHP_OS_FAMILY !== 'Windows' && function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return null;
    }

    private function assertIdentity(string $kind, string $name): void
    {
        if (!in_array($kind, ['worker', 'schedule'], true)) {
            throw new \InvalidArgumentException('Runtime process kind must be worker or schedule.');
        }
        if ($name === '' || preg_match('/^[A-Za-z0-9_.:-]{1,191}$/D', $name) !== 1) {
            throw new \InvalidArgumentException('Runtime process name contains unsupported characters.');
        }
    }

    private function directory(): string
    {
        $configured = $this->application->config()->get(
            'runtime.registry.path',
            'storage/framework/runtime',
        );
        $configured = is_string($configured) && $configured !== ''
            ? $configured
            : 'storage/framework/runtime';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : $this->application->basePath(trim($configured, DIRECTORY_SEPARATOR));
    }
}
