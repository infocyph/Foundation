<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Runtime;

/**
 * Process-lifetime lease protecting an immutable release generation from pruning.
 *
 * The marker is created during release build. Runtime processes only open it for
 * reading and hold a shared advisory lock; build-plane pruning requires an
 * exclusive non-blocking lock before deleting the generation.
 */
final class ReleaseGenerationLease
{
    public const string MARKER = '.foundation-runtime.lock';

    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    private function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function acquireShared(string $releaseRoot, string $generation): self
    {
        $lease = self::acquire($releaseRoot, $generation, LOCK_SH | LOCK_NB);
        if (!$lease instanceof self) {
            throw new \RuntimeException(sprintf(
                'Foundation release generation "%s" is being retired.',
                $generation,
            ));
        }

        return $lease;
    }

    public static function initialize(string $generationDirectory): void
    {
        if (!is_dir($generationDirectory)) {
            throw new \RuntimeException(sprintf(
                'Foundation release generation directory "%s" does not exist.',
                $generationDirectory,
            ));
        }

        $path = rtrim($generationDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . self::MARKER;
        if (file_put_contents($path, '', LOCK_EX) === false) {
            throw new \RuntimeException('Unable to create the Foundation runtime generation lease marker.');
        }
    }

    public static function tryExclusive(string $releaseRoot, string $generation): ?self
    {
        return self::acquire($releaseRoot, $generation, LOCK_EX | LOCK_NB);
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

    /** @param int<0, 7> $operation */
    private static function acquire(string $releaseRoot, string $generation, int $operation): ?self
    {
        $path = self::path($releaseRoot, $generation);
        if (!is_file($path)) {
            throw new \RuntimeException(sprintf(
                'Foundation release generation "%s" has no runtime lease marker.',
                $generation,
            ));
        }

        $handle = fopen($path, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException(sprintf(
                'Unable to open the runtime lease for Foundation generation "%s".',
                $generation,
            ));
        }
        if (!flock($handle, $operation)) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    private static function path(string $releaseRoot, string $generation): string
    {
        self::validateGeneration($generation);

        return rtrim($releaseRoot, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . 'generations'
            . DIRECTORY_SEPARATOR
            . $generation
            . DIRECTORY_SEPARATOR
            . self::MARKER;
    }

    private static function validateGeneration(string $generation): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $generation) !== 1) {
            throw new \InvalidArgumentException('Foundation release generation identifier is invalid.');
        }
    }
}
