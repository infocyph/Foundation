<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Application\Application;

final readonly class StorageLinkManager
{
    public function __construct(private Application $application) {}

    /** @return list<array{link:string,target:string,created:bool}> */
    public function create(): array
    {
        $configured = $this->application->config()->get('filesystem.links', []);
        if (!is_array($configured) || $configured === []) {
            throw new \RuntimeException('No filesystem.links are configured.');
        }

        $links = [];
        foreach ($configured as $link => $target) {
            if (!is_string($link) || $link === '' || !is_string($target) || $target === '') {
                throw new \InvalidArgumentException('filesystem.links must map link paths to target paths.');
            }
            $links[] = $this->link($this->absolute($link), $this->absolute($target));
        }

        return $links;
    }

    private function absolute(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : $this->application->basePath(trim($path, '/\\'));
    }

    private function assertInside(string $path, string $root, string $label): void
    {
        $root = rtrim($root, DIRECTORY_SEPARATOR);
        if (preg_match('~(?:^|[\\\\/])\.\.(?:[\\\\/]|$)~', $path) === 1) {
            throw new \RuntimeException($label . ' cannot contain parent-directory traversal.');
        }
        if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException(sprintf('%s must remain inside "%s".', $label, $root));
        }
    }

    /** @return array{link:string,target:string,created:bool} */
    private function link(string $link, string $target): array
    {
        $storage = realpath($this->application->storagePath());
        $public = realpath($this->application->publicPath());
        if ($storage === false || $public === false) {
            throw new \RuntimeException('The configured storage and public directories must exist.');
        }

        $this->assertInside($target, $storage, 'Storage-link target');
        $this->assertInside($link, $public, 'Storage-link path');
        [$link, $target] = $this->preparePaths($link, $target, $storage);
        $this->assertInside($target, $storage, 'Storage-link target');
        $this->assertInside($link, $public, 'Storage-link path');

        if (is_link($link)) {
            if (realpath($link) !== $target) {
                throw new \RuntimeException(sprintf('A different symbolic link already exists at "%s".', $link));
            }

            return ['link' => $link, 'target' => $target, 'created' => false];
        }
        if (file_exists($link)) {
            throw new \RuntimeException(sprintf('A file or directory already exists at "%s".', $link));
        }

        $temporary = $link . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!symlink($target, $temporary)) {
            throw new \RuntimeException(sprintf('Unable to create symbolic link "%s".', $link));
        }

        try {
            if (!rename($temporary, $link)) {
                throw new \RuntimeException(sprintf('Unable to activate symbolic link "%s".', $link));
            }
        } finally {
            if (is_link($temporary)) {
                unlink($temporary);
            }
        }

        return ['link' => $link, 'target' => $target, 'created' => true];
    }

    /** @return array{0:string,1:string} */
    private function preparePaths(string $link, string $target, string $storage): array
    {
        $ancestor = $target;
        while (!file_exists($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) {
                throw new \RuntimeException(sprintf('Storage-link target has no existing ancestor: %s', $target));
            }
            $ancestor = $parent;
        }
        $ancestor = realpath($ancestor);
        if ($ancestor === false) {
            throw new \RuntimeException(sprintf('Unable to resolve storage-link target ancestor: %s', $target));
        }
        $this->assertInside($ancestor, $storage, 'Storage-link target ancestor');

        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException(sprintf('Unable to create storage-link target "%s".', $target));
        }
        $parent = realpath(dirname($link));
        if ($parent === false) {
            throw new \RuntimeException(sprintf('Storage-link parent does not exist: %s', dirname($link)));
        }

        return [$parent . DIRECTORY_SEPARATOR . basename($link), realpath($target) ?: $target];
    }
}
