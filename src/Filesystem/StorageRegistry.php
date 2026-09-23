<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Pathwise\Storage\StorageContext;
use Infocyph\Pathwise\Utils\PathHelper;
use League\Flysystem\FilesystemOperator;

/**
 * Thin Foundation application-policy adapter over Pathwise StorageContext.
 *
 * Named filesystem lifecycle, lazy operator creation, path identity, custom
 * drivers, and isolation are Pathwise-owned. Foundation only loads application
 * configuration and resolves relative local roots against the application base.
 */
final readonly class StorageRegistry
{
    private StorageContext $context;

    /**
     * @param array<array-key, mixed> $drivers Optional Pathwise custom-driver factories.
     */
    public function __construct(
        ConfigRepository $config,
        PathManager $paths,
        array $drivers = [],
    ) {
        $configured = $config->get('filesystem.default', 'local');
        $default = is_string($configured) && trim($configured) !== ''
            ? trim($configured)
            : 'local';

        $this->context = new StorageContext(
            $this->loadConfigurations($config, $paths),
            $default,
            $drivers,
        );
    }

    /** @return array<string, mixed> */
    public function configuration(?string $name = null): array
    {
        return $this->context->configuration($name);
    }

    public function context(): StorageContext
    {
        return $this->context;
    }

    public function defaultDisk(): string
    {
        return $this->context->defaultFilesystem();
    }

    public function disk(?string $name = null): FilesystemOperator
    {
        return $this->context->filesystem($name);
    }

    /** @return list<string> */
    public function disks(): array
    {
        return $this->context->filesystemNames();
    }

    public function localPath(string $path = '', ?string $disk = null): string
    {
        return $this->context->localPath($path, $disk);
    }

    public function path(string $path = '', ?string $disk = null): string
    {
        return $this->context->path($path, $disk);
    }

    public function resolveDisk(?string $name): string
    {
        $candidate = is_string($name) && trim($name) !== ''
            ? strtolower(trim($name))
            : $this->context->defaultFilesystem();

        $this->context->configuration($candidate);

        return $candidate;
    }

    /** @return array<string, array<string, mixed>> */
    private function loadConfigurations(ConfigRepository $config, PathManager $paths): array
    {
        $configured = $config->get('filesystem.disks', []);
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

            $normalized = ValueNormalizer::associativeArray($configuration);
            $root = $normalized['root'] ?? null;
            $driver = $normalized['driver'] ?? 'local';
            if (
                is_string($root)
                && trim($root) !== ''
                && is_string($driver)
                && strtolower(trim($driver)) === 'local'
                && !PathHelper::isAbsolute($root)
            ) {
                $normalized['root'] = $paths->base($root);
            }

            $filesystems[$name] = $normalized;
        }

        return $filesystems;
    }
}
