<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Webrick\Router\Build\ReleaseManifestLoader;
use RuntimeException;

/**
 * Read-only, short-lived stream over previously SHA-256-verified manifest bytes.
 * Webrick validates these exact bytes without request-time temporary PHP files.
 *
 * @internal
 */
final class VerifiedManifestStream
{
    private const string SCHEME = 'foundation-verified-manifest';

    private static bool $registered = false;

    /** @var array<string, string> */
    private static array $snapshots = [];

    /** @var resource|null */
    public mixed $context = null;

    // PHP stream callbacks are invoked by the runtime, not a PHP caller.
    // Their public state is confined to this internal wrapper instance.
    public string $contents = '';

    public int $offset = 0;

    /**
     * @return array<string, mixed>
     * @throws \JsonException
     * @throws RuntimeException
     * @throws \UnexpectedValueException
     */
    public static function load(string $verifiedBytes, bool $runtimePhp): array
    {
        if (!self::$registered) {
            if (in_array(self::SCHEME, stream_get_wrappers(), true)
                || !stream_wrapper_register(self::SCHEME, self::class)) {
                throw new RuntimeException('Verified release manifest stream scheme is unavailable.');
            }
            self::$registered = true;
        } elseif (!in_array(self::SCHEME, stream_get_wrappers(), true)) {
            throw new RuntimeException('Verified release manifest stream registration has been removed.');
        }

        $base = self::SCHEME . '://' . bin2hex(random_bytes(12)) . '/release';
        $path = $base . ($runtimePhp ? '.php' : '.json');
        self::$snapshots[$path] = $verifiedBytes;

        try {
            return new ReleaseManifestLoader()->load($base . '.json');
        } finally {
            unset(self::$snapshots[$path]);
        }
    }

    /** @return array<int|string, int> */
    public static function stat(int $size): array
    {
        return [
            0 => 0, 1 => 0, 2 => 0100444, 3 => 1, 4 => 0, 5 => 0, 6 => 0, 7 => $size,
            8 => 0, 9 => 0, 10 => 0, 11 => 4096, 12 => 1,
            'dev' => 0, 'ino' => 0, 'mode' => 0100444, 'nlink' => 1, 'uid' => 0,
            'gid' => 0, 'rdev' => 0, 'size' => $size, 'atime' => 0, 'mtime' => 0,
            'ctime' => 0, 'blksize' => 4096, 'blocks' => 1,
        ];
    }

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$opened_path): bool
    {
        unset($options, $opened_path);
        if (!in_array($mode, ['r', 'rb'], true) || !isset(self::$snapshots[$path])) {
            return false;
        }

        $this->contents = self::$snapshots[$path];
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->contents, $this->offset, $count);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_SET => $offset,
            SEEK_CUR => $this->offset + $offset,
            SEEK_END => strlen($this->contents) + $offset,
            default => -1,
        };
        if ($position < 0 || $position > strlen($this->contents)) {
            return false;
        }

        $this->offset = $position;

        return true;
    }

    public function stream_set_option(int $option, int $arg1, int $arg2): bool
    {
        unset($option, $arg1, $arg2);

        return false;
    }

    /** @return array<int|string, int> */
    public function stream_stat(): array
    {
        return self::stat(strlen($this->contents));
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    /** @return array<int|string, int>|false */
    public function url_stat(string $path, int $flags): array|false
    {
        unset($flags);

        return isset(self::$snapshots[$path])
            ? self::stat(strlen(self::$snapshots[$path]))
            : false;
    }
}
