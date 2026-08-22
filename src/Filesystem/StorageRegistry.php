<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Foundation-owned storage configuration and disk selection.
 *
 * Pathwise/Flysystem own filesystem operations. This registry only turns the
 * application's filesystem.disks configuration into named native filesystems
 * and resolves application-relative paths to those mounts.
 */
final class StorageRegistry
{
    /** @var array<string, FilesystemOperator> */
    private array $filesystems = [];

    private bool $initialized = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PathManager $paths,
    ) {}

    public function defaultDisk(): string
    {
        $configured = $this->config->get('filesystem.default', 'local');
        $disk = is_string($configured) && trim($configured) !== ''
            ? $this->normalizeDiskName($configured)
            : 'local';

        if (!array_key_exists($disk, $this->configurations())) {
            throw new \InvalidArgumentException(sprintf(
                'Default filesystem disk "%s" is not configured.',
                $disk,
            ));
        }

        return $disk;
    }

    public function disk(?string $name = null): FilesystemOperator
    {
        $this->initialize();
        $disk = $this->resolveDisk($name);

        return $this->filesystems[$disk] ?? throw new \InvalidArgumentException(sprintf(
            'Filesystem disk "%s" is not configured.',
            $disk,
        ));
    }

    /** @return list<string> */
    public function disks(): array
    {
        return array_keys($this->configurations());
    }

    /** @return array<string, mixed> */
    public function configuration(?string $name = null): array
    {
        $disk = $this->resolveDisk($name);

        return $this->configurations()[$disk] ?? throw new \InvalidArgumentException(sprintf(
            'Filesystem disk "%s" is not configured.',
            $disk,
        ));
    }

    public function initialize(): void
    {
        if ($this->initialized) {
            return;
        }

        $prepared = [];
        foreach ($this->configurations() as $disk => $configuration) {
            $prepared[$disk] = PathwiseFacade::createFilesystem(
                $this->normalizeFilesystemConfig($configuration),
            );
        }

        $default = $this->defaultDisk();
        if (!isset($prepared[$default])) {
            throw new \InvalidArgumentException(sprintf(
                'Default filesystem disk "%s" is not configured.',
                $default,
            ));
        }

        // Replace only Foundation-owned mount names. Do not reset Pathwise's
        // process-wide registry because applications may register independent
        // Pathwise mounts outside Foundation.
        foreach ($prepared as $disk => $filesystem) {
            FlysystemHelper::replaceMount($disk, $filesystem);
        }
        FlysystemHelper::setDefaultFilesystem($prepared[$default]);

        $this->filesystems = $prepared;
        $this->initialized = true;
    }

    public function localPath(string $path = '', ?string $disk = null): string
    {
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        $resolved = $this->resolveDisk($disk);
        $configuration = $this->configuration($resolved);
        $driver = $configuration['driver'] ?? 'local';
        if (!is_string($driver) || strtolower(trim($driver)) !== 'local') {
            throw new \InvalidArgumentException(sprintf(
                'Filesystem disk "%s" is not a local disk.',
                $resolved,
            ));
        }

        $root = $configuration['root'] ?? null;
        if (!is_string($root) || $root === '') {
            throw new \InvalidArgumentException(sprintf(
                'Local filesystem disk "%s" requires a root path.',
                $resolved,
            ));
        }
        $root = PathHelper::isAbsolute($root)
            ? PathHelper::normalize($root)
            : $this->paths->base($root);
        $relative = trim(str_replace('\\', '/', $path), '/');

        return $relative === '' ? $root : PathHelper::join($root, $relative);
    }

    public function path(string $path = '', ?string $disk = null): string
    {
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        $this->initialize();
        $resolved = $this->resolveDisk($disk);
        if (!isset($this->filesystems[$resolved])) {
            throw new \InvalidArgumentException(sprintf(
                'Filesystem disk "%s" is not configured.',
                $resolved,
            ));
        }

        $relative = trim(str_replace('\\', '/', $path), '/');

        return $relative === ''
            ? $resolved . '://'
            : $resolved . '://' . $relative;
    }

    public function resolveDisk(?string $name): string
    {
        $candidate = trim($name ?? '');

        return $candidate === '' ? $this->defaultDisk() : $this->normalizeDiskName($candidate);
    }

    /** @return array<string, array<string, mixed>> */
    private function configurations(): array
    {
        $configured = $this->config->get('filesystem.disks', []);
        if (!is_array($configured)) {
            throw new \InvalidArgumentException('filesystem.disks must be an associative disk map.');
        }

        $filesystems = [];
        foreach ($configured as $name => $configuration) {
            if (!is_string($name) || trim($name) === '' || !is_array($configuration)) {
                throw new \InvalidArgumentException(
                    'filesystem.disks must map non-empty disk names to configuration arrays.',
                );
            }

            $disk = $this->normalizeDiskName($name);
            if (isset($filesystems[$disk])) {
                throw new \InvalidArgumentException(sprintf(
                    'Filesystem disk "%s" is configured more than once.',
                    $disk,
                ));
            }
            $filesystems[$disk] = ValueNormalizer::associativeArray($configuration);
        }

        if ($filesystems === []) {
            throw new \InvalidArgumentException('At least one filesystem disk must be configured.');
        }

        return $filesystems;
    }

    /** @param array<string, mixed> $configuration @return array<string, mixed> */
    private function normalizeFilesystemConfig(array $configuration): array
    {
        $root = $configuration['root'] ?? null;
        $driver = $configuration['driver'] ?? 'local';
        if (is_string($root)
            && $root !== ''
            && !PathHelper::isAbsolute($root)
            && is_string($driver)
            && strtolower(trim($driver)) === 'local'
        ) {
            $configuration['root'] = $this->paths->base($root);
        }

        return $configuration;
    }

    private function normalizeDiskName(string $name): string
    {
        $disk = strtolower(trim($name));
        if (preg_match('/^[a-z][a-z0-9._-]*$/D', $disk) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid filesystem disk name "%s".',
                $name,
            ));
        }

        return $disk;
    }
}
