<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Release;

/** Deterministic identity for an immutable release-owned directory tree. */
final class FoundationReleaseTreeDigest
{
    public static function calculate(string $directory): string
    {
        if (!is_dir($directory)) {
            throw new \RuntimeException(sprintf(
                'Foundation release directory is missing: "%s".',
                $directory,
            ));
        }

        $root = rtrim($directory, DIRECTORY_SEPARATOR);
        $entries = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $root,
                \FilesystemIterator::SKIP_DOTS,
            ),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            /** @var \SplFileInfo $entry */
            $path = $entry->getPathname();
            $relative = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);

            if ($entry->isLink()) {
                $target = readlink($path);
                if (!is_string($target)) {
                    throw new \RuntimeException(sprintf(
                        'Unable to read Foundation release symlink "%s".',
                        $relative,
                    ));
                }
                $entries[] = 'link:' . $relative . ':' . $target;

                continue;
            }
            if ($entry->isDir()) {
                continue;
            }
            if (!$entry->isFile()) {
                throw new \RuntimeException(sprintf(
                    'Unsupported Foundation release tree entry "%s".',
                    $relative,
                ));
            }

            $sha256 = hash_file('sha256', $path);
            if (!is_string($sha256)) {
                throw new \RuntimeException(sprintf(
                    'Unable to fingerprint Foundation release file "%s".',
                    $relative,
                ));
            }
            $entries[] = 'file:' . $relative . ':' . $sha256;
        }

        sort($entries, SORT_STRING);

        return hash('sha256', implode("\n", $entries));
    }

    public static function assertMatches(string $directory, string $expectedSha256): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $expectedSha256) !== 1) {
            throw new \UnexpectedValueException('Foundation release tree SHA-256 is invalid.');
        }

        $actual = self::calculate($directory);
        if (!hash_equals($expectedSha256, $actual)) {
            throw new \RuntimeException('Foundation release tree trust identity mismatch.');
        }
    }
}
