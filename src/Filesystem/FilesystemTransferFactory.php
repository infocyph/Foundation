<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\PathHelper;

/**
 * Applies Foundation's application transfer policy to native Pathwise
 * processors. Upload/download execution remains entirely Pathwise-owned.
 */
final readonly class FilesystemTransferFactory
{
    public function __construct(
        private ConfigRepository $config,
        private PathManager $paths,
        private StorageRegistry $storage,
    ) {}

    public function download(?string $directory = null, ?string $disk = null): DownloadProcessor
    {
        $config = $this->section('downloads');
        $disk = $this->targetDisk($disk, $this->string($config, 'disk', 'uploads'));
        $directoryPath = $this->operationPath(
            $disk,
            $directory ?? $this->string($config, 'directory'),
        );
        $allowedRoots = [];
        foreach (ValueNormalizer::stringList($config['allowed_roots'] ?? []) as $root) {
            $allowedRoots[] = PathHelper::isAbsolute($root)
                ? PathHelper::normalize($root)
                : $this->operationPath($disk, $root);
        }

        $processor = PathwiseFacade::download();
        $processor->setAllowedRoots($allowedRoots !== [] ? $allowedRoots : [$directoryPath]);
        $processor->setBlockHiddenFiles($this->bool($config, 'block_hidden_files', true));
        $processor->setChunkSize($this->int($config, 'chunk_size', 8192));
        $processor->setDefaultDownloadName($this->string($config, 'default_name', 'download.bin'));
        $processor->setExtensionPolicy(
            ValueNormalizer::stringList($config['allowed_extensions'] ?? []),
            ValueNormalizer::stringList($config['blocked_extensions'] ?? []),
        );
        $processor->setForceAttachment($this->bool($config, 'force_attachment', true));
        $processor->setMaxDownloadSize($this->int($config, 'max_size', 0));
        $processor->setRangeRequestsEnabled($this->bool($config, 'range_requests', true));

        return $processor;
    }

    public function upload(?string $directory = null, ?string $disk = null): UploadProcessor
    {
        $config = $this->section('uploads');
        $disk = $this->targetDisk($disk, $this->string($config, 'disk', 'uploads'));

        $processor = PathwiseFacade::upload();
        $processor->setDirectorySettings(
            $this->operationPath($disk, $directory ?? $this->string($config, 'directory')),
            $this->bool($config, 'use_date_directories', false),
            $this->basePath($config['temp_directory'] ?? null),
        );
        $processor->setExtensionPolicy(
            ValueNormalizer::stringList($config['allowed_extensions'] ?? []),
            ValueNormalizer::stringList($config['blocked_extensions'] ?? []),
        );
        $processor->setChunkLimits(
            $this->int($config, 'max_chunk_count', 0),
            $this->int($config, 'max_chunk_size', 0),
        );

        $profile = $config['validation_profile'] ?? null;
        if (is_string($profile) && trim($profile) !== '') {
            $processor->setValidationProfile(trim($profile));
        } else {
            $processor->setValidationSettings(
                ValueNormalizer::stringList($config['allowed_file_types'] ?? []),
                $this->int($config, 'max_file_size', 5 * 1024 * 1024),
            );
        }

        $processor->setImageValidationSettings(
            $this->int($config, 'max_image_width', 0),
            $this->int($config, 'max_image_height', 0),
        );
        $processor->setNamingStrategy($this->string($config, 'naming_strategy', 'hash'));
        $processor->setRequireMalwareScan($this->bool($config, 'require_malware_scan', false));
        $processor->setStrictContentTypeValidation(
            $this->bool($config, 'strict_content_type_validation', true),
        );

        return $processor;
    }

    private function basePath(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return PathHelper::isAbsolute($value)
            ? PathHelper::normalize($value)
            : $this->paths->base($value);
    }

    /** @param array<string, mixed> $config */
    private function bool(array $config, string $key, bool $default): bool
    {
        return ValueNormalizer::bool($config[$key] ?? $default, $default);
    }

    /** @param array<string, mixed> $config */
    private function int(array $config, string $key, int $default): int
    {
        return ValueNormalizer::int($config[$key] ?? $default, $default);
    }

    private function operationPath(string $disk, string $path = ''): string
    {
        try {
            return $this->storage->localPath($path, $disk);
        } catch (\InvalidArgumentException) {
            return $this->storage->path($path, $disk);
        }
    }

    /** @return array<string, mixed> */
    private function section(string $name): array
    {
        return ValueNormalizer::associativeArray(
            $this->config->get('filesystem.' . $name, []),
        );
    }

    /** @param array<string, mixed> $config */
    private function string(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value) ? $value : $default;
    }

    private function targetDisk(?string $disk, string $configured): string
    {
        if (is_string($disk) && trim($disk) !== '') {
            return $this->storage->resolveDisk($disk);
        }
        if (trim($configured) !== '') {
            return $this->storage->resolveDisk($configured);
        }

        return $this->storage->defaultDisk();
    }
}
