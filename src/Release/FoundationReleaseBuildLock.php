<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

final class FoundationReleaseBuildLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public static function acquire(string $releaseRoot): self
    {
        if (!is_dir($releaseRoot) && !mkdir($releaseRoot, 0775, true) && !is_dir($releaseRoot)) {
            throw new \RuntimeException(sprintf('Unable to create Foundation release root "%s".', $releaseRoot));
        }

        $path = rtrim($releaseRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.foundation-build.lock';
        $handle = fopen($path, 'c+');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Unable to open the Foundation release build lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            throw new \RuntimeException('Another Foundation release build is already in progress.');
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }

        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }
}
