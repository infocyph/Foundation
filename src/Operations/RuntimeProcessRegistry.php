<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Operations;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\UID\Id;

final readonly class RuntimeProcessRegistry
{
    public function __construct(private Application $application) {}

    /** @return array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:true} */
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
            'host' => $this->host(),
        ];
        $this->write($record);

        return [...$record, 'running' => true];
    }

    /**
     * @param array<int|string, mixed> $record
     * @return array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:true}
     */
    public function heartbeat(array $record): array
    {
        $normalized = $this->normalizeRecord($record);
        if ($normalized === null) {
            throw new \InvalidArgumentException('Runtime process record is invalid.');
        }

        $normalized['heartbeat_at'] = gmdate(DATE_ATOM);
        $this->write($normalized);

        return [...$normalized, 'running' => true];
    }

    /** @return list<array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string,running:bool}> */
    public function all(?string $kind = null, ?string $name = null): array
    {
        $directory = $this->directory();
        if (!is_dir($directory)) {
            return [];
        }

        $visibility = $this->visibility();
        $host = $this->host();
        $records = [];
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $record = $this->read($path);
            if ($record === null) {
                continue;
            }
            if ($visibility === 'host' && !hash_equals($host, $record['host'])) {
                continue;
            }
            if ($kind !== null && $record['kind'] !== $kind) {
                continue;
            }
            if ($name !== null && $record['name'] !== $name) {
                continue;
            }
            $records[] = [...$record, 'running' => $this->heartbeatFresh($record['heartbeat_at'])];
        }

        usort($records, static fn(array $left, array $right): int => $right['started_at'] <=> $left['started_at']);

        return $records;
    }

    /** @param array<int|string, mixed> $record */
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

    public function visibility(): string
    {
        $visibility = strtolower(ValueNormalizer::string(
            $this->application->config()->get('operations.runtime_registry.visibility', 'host'),
            'host',
        ));
        if (!in_array($visibility, ['host', 'shared'], true)) {
            throw new \UnexpectedValueException(
                'operations.runtime_registry.visibility must be host or shared.',
            );
        }

        return $visibility;
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

        return is_array($record) ? $this->normalizeRecord($record) : null;
    }

    /**
     * @param array<int|string, mixed> $record
     * @return array{id:string,kind:string,name:string,pid:int,started_at:string,heartbeat_at:string,host:string}|null
     */
    private function normalizeRecord(array $record): ?array
    {
        $id = $record['id'] ?? null;
        $kind = $record['kind'] ?? null;
        $name = $record['name'] ?? null;
        $pid = $record['pid'] ?? null;
        $startedAt = $record['started_at'] ?? null;
        $heartbeatAt = $record['heartbeat_at'] ?? null;
        $host = $record['host'] ?? null;
        if (!is_string($id)
            || !is_string($kind)
            || !is_string($name)
            || !is_int($pid)
            || !is_string($startedAt)
            || !is_string($heartbeatAt)
            || !is_string($host)
        ) {
            return null;
        }

        return [
            'id' => $id,
            'kind' => $kind,
            'name' => $name,
            'pid' => $pid,
            'started_at' => $startedAt,
            'heartbeat_at' => $heartbeatAt,
            'host' => $host,
        ];
    }

    private function heartbeatFresh(string $heartbeatAt): bool
    {
        $timestamp = strtotime($heartbeatAt);
        if (!is_int($timestamp)) {
            return false;
        }
        $configured = $this->application->config()->get('operations.runtime_registry.stale_seconds', 15);
        $staleSeconds = is_int($configured) && $configured > 0 ? $configured : 15;

        return time() - $timestamp <= $staleSeconds;
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
            'operations.runtime_registry.path',
            'storage/framework/runtime',
        );
        $configured = is_string($configured) && $configured !== ''
            ? $configured
            : 'storage/framework/runtime';

        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $configured) === 1
            ? rtrim($configured, DIRECTORY_SEPARATOR)
            : $this->application->basePath(trim($configured, DIRECTORY_SEPARATOR));
    }

    private function host(): string
    {
        $host = gethostname();

        return is_string($host) && $host !== '' ? $host : 'unknown';
    }
}
