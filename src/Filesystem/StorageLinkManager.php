<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;

final readonly class StorageLinkManager
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
    ) {}

    /** @return list<array{link:string,target:string,created:bool}> */
    public function create(): array
    {
        $links = [];
        foreach ($this->configured() as $mapping) {
            $links[] = $this->link($mapping['link'], $mapping['target']);
        }

        return $links;
    }

    /** @return list<array{link:string,target:string,exists:bool,linked:bool,matches:bool}> */
    public function status(): array
    {
        $status = [];
        foreach ($this->configured() as $mapping) {
            $link = $mapping['link'];
            $target = $mapping['target'];
            $linked = is_link($link);
            $resolved = $linked ? realpath($link) : false;
            $status[] = [
                'link' => $link,
                'target' => $target,
                'exists' => $linked || file_exists($link),
                'linked' => $linked,
                'matches' => $linked && $resolved !== false && $resolved === realpath($target),
            ];
        }

        return $status;
    }

    /** @return list<array{link:string,target:string,removed:bool}> */
    public function remove(): array
    {
        $removed = [];
        foreach ($this->configured() as $mapping) {
            $link = $mapping['link'];
            $target = $mapping['target'];
            if (!is_link($link)) {
                if (file_exists($link)) {
                    throw new \RuntimeException(sprintf(
                        'Refusing to remove non-symbolic path "%s".',
                        $link,
                    ));
                }
                $removed[] = ['link' => $link, 'target' => $target, 'removed' => false];

                continue;
            }
            $resolved = realpath($link);
            $expected = realpath($target);
            if ($resolved === false || $expected === false || $resolved !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Refusing to remove storage link "%s" because its current target does not match configuration.',
                    $link,
                ));
            }
            if (!unlink($link)) {
                throw new \RuntimeException(sprintf('Unable to remove symbolic link "%s".', $link));
            }
            $removed[] = ['link' => $link, 'target' => $target, 'removed' => true];
        }

        return $removed;
    }

    /** @return list<array{link:string,target:string}> */
    private function configured(): array
    {
        $configured = $this->config->get('filesystem.links', []);
        if (!is_array($configured) || $configured === []) {
            throw new \RuntimeException('No filesystem.links are configured.');
        }

        $storage = realpath($this->paths->storage());
        $public = realpath($this->paths->public());
        if ($storage === false || $public === false) {
            throw new \RuntimeException('The configured storage and public directories must exist.');
        }

        $links = [];
        foreach ($configured as $link => $target) {
            if (!is_string($link) || $link === '' || !is_string($target) || $target === '') {
                throw new \InvalidArgumentException('filesystem.links must map link paths to target paths.');
            }
            $link = $this->absolute($link);
            $target = $this->absolute($target);
            $this->assertInside($target, $storage, 'Storage-link target');
            $this->assertInside($link, $public, 'Storage-link path');
            $links[] = ['link' => $link, 'target' => $target];
        }

        return $links;
    }

    private function absolute(string $path): string
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1
            ? rtrim($path, DIRECTORY_SEPARATOR)
            : $this->paths->base(trim($path, '/\\'));
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
        $storage = realpath($this->paths->storage());
        $public = realpath($this->paths->public());
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
