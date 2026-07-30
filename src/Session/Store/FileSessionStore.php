<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Session\Store;

use Infocyph\Foundation\Session\SessionPayload;
use Infocyph\Foundation\Session\SessionStoreInterface;

final readonly class FileSessionStore implements SessionStoreInterface
{
    public function __construct(private string $directory) {}

    public function delete(string $id): void
    {
        $path = $this->path($id);
        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to delete session file "%s".', $path));
        }
    }

    public function load(string $id, int $now): ?SessionPayload
    {
        $path = $this->path($id);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if (!is_string($contents)) {
            return null;
        }

        $payload = SessionPayload::fromJson($contents);
        if ($payload === null || $payload->expiresAt <= $now) {
            $this->delete($id);

            return null;
        }

        return $payload;
    }

    public function prune(int $now, int $limit = 1_000): int
    {
        if (!is_dir($this->directory)) {
            return 0;
        }

        $iterator = new \FilesystemIterator($this->directory, \FilesystemIterator::SKIP_DOTS);
        $pruned = 0;
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo
                || !$file->isFile()
                || !str_ends_with($file->getFilename(), '.json')
            ) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            $decoded = is_string($contents) ? json_decode($contents, true) : null;
            $expiresAt = is_array($decoded) ? ($decoded['expires_at'] ?? null) : null;
            if (is_int($expiresAt) && $expiresAt > $now) {
                continue;
            }

            if (unlink($file->getPathname()) && ++$pruned >= $limit) {
                break;
            }
        }

        return $pruned;
    }

    public function save(string $id, SessionPayload $payload): void
    {
        $this->ensureDirectory();
        $path = $this->path($id);
        $temporary = tempnam($this->directory, '.session-');
        if (!is_string($temporary)) {
            throw new \RuntimeException(sprintf('Unable to create a temporary session file in "%s".', $this->directory));
        }

        try {
            if (file_put_contents($temporary, $payload->toJson(), LOCK_EX) === false || !rename($temporary, $path)) {
                throw new \RuntimeException(sprintf('Unable to write session file "%s".', $path));
            }
            chmod($path, 0600);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)
            || mkdir($this->directory, 0700, true)
            || is_dir($this->directory)
        ) {
            return;
        }

        throw new \RuntimeException(sprintf('Unable to create session directory "%s".', $this->directory));
    }

    private function path(string $id): string
    {
        return rtrim($this->directory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . hash('sha256', $id)
            . '.json';
    }
}
