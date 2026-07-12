<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Filesystem;

use DateTimeInterface;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Pathwise\Observability\AuditTrail;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Pathwise\Queue\FileJobQueue;
use Infocyph\Pathwise\Security\PolicyEngine;
use Infocyph\Pathwise\StreamHandler\DownloadProcessor;
use Infocyph\Pathwise\StreamHandler\UploadProcessor;
use Infocyph\Pathwise\Utils\FlysystemHelper;
use Infocyph\Pathwise\Utils\PathHelper;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use League\Flysystem\FilesystemOperator;

final class FilesystemManager
{
    /**
     * @var array<string, FilesystemOperator>
     */
    private array $filesystems = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly PathManager $paths,
    ) {
        $this->mountConfiguredFilesystems();
    }

    public function at(string $path = '', ?string $disk = null): PathwiseFacade
    {
        return PathwiseFacade::at($this->path($path, $disk));
    }

    public function audit(string $logFilePath): AuditTrail
    {
        return PathwiseFacade::audit($this->baseRelativePath($logFilePath));
    }

    public function base(string $path = ''): string
    {
        return $this->paths->base($path);
    }

    public function bootstrap(string $path = ''): string
    {
        return $this->paths->bootstrap($path);
    }

    public function cache(string $path = ''): string
    {
        return $this->paths->cache($path);
    }

    public function checksum(string $path, string $algorithm = 'sha256', ?string $disk = null): ?string
    {
        return FlysystemHelper::checksum($this->path($path, $disk), $algorithm);
    }

    public function config(string $path = ''): string
    {
        return $this->paths->config($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function copy(
        string $source,
        string $destination,
        ?string $sourceDisk = null,
        ?string $destinationDisk = null,
        array $config = [],
    ): void {
        FlysystemHelper::copy(
            $this->path($source, $sourceDisk),
            $this->path($destination, $destinationDisk),
            $config,
        );
    }

    public function database(string $path = ''): string
    {
        return $this->paths->database($path);
    }

    /**
     * @return array{linked: list<string>, skipped: list<string>}
     */
    public function deduplicate(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return PathwiseFacade::deduplicate($this->path($directory, $disk), $algorithm);
    }

    public function delete(string $path, ?string $disk = null): void
    {
        FlysystemHelper::delete($this->path($path, $disk));
    }

    public function deleteDirectory(string $path = '', ?string $disk = null): void
    {
        FlysystemHelper::deleteDirectory($this->path($path, $disk));
    }

    /**
     * @param array<string, array{mtime: int, size: int}> $previousSnapshot
     * @param array<string, array{mtime: int, size: int}> $currentSnapshot
     * @return array<string, array<int|string, mixed>>
     */
    public function diffSnapshots(array $previousSnapshot, array $currentSnapshot): array
    {
        return PathwiseFacade::diffSnapshots($previousSnapshot, $currentSnapshot);
    }

    public function disk(?string $name = null): FilesystemOperator
    {
        $disk = $this->resolveDisk($name);

        if (isset($this->filesystems[$disk])) {
            return $this->filesystems[$disk];
        }

        throw new \InvalidArgumentException(sprintf('Filesystem disk "%s" is not configured.', $disk));
    }

    public function diskPath(string $disk, string $path = ''): string
    {
        $resolvedDisk = $this->resolveDisk($disk);
        $relativePath = trim(str_replace('\\', '/', $path), '/');

        return $relativePath === ''
            ? $resolvedDisk . '://'
            : $resolvedDisk . '://' . $relativePath;
    }

    public function download(?string $directory = null, ?string $disk = null): DownloadProcessor
    {
        $config = $this->arrayConfig('downloads');
        $processor = PathwiseFacade::download();
        $diskName = $this->filesystemTargetDisk(
            $disk,
            $this->stringArrayValue($config, 'disk', 'uploads'),
        );
        $directoryPath = $this->operationPath(
            $diskName,
            $directory ?? $this->stringArrayValue($config, 'directory'),
        );
        $allowedRoots = $this->downloadAllowedRoots($config, $diskName);

        $processor->setAllowedRoots($allowedRoots !== [] ? $allowedRoots : [$directoryPath]);
        $processor->setBlockHiddenFiles($this->boolArrayValue($config, 'block_hidden_files', true));
        $processor->setChunkSize($this->intArrayValue($config, 'chunk_size', 8192));
        $processor->setDefaultDownloadName($this->stringArrayValue($config, 'default_name', 'download.bin'));
        $processor->setExtensionPolicy(
            $this->stringList($config['allowed_extensions'] ?? []),
            $this->stringList($config['blocked_extensions'] ?? []),
        );
        $processor->setForceAttachment($this->boolArrayValue($config, 'force_attachment', true));
        $processor->setMaxDownloadSize($this->intArrayValue($config, 'max_size', 0));
        $processor->setRangeRequestsEnabled($this->boolArrayValue($config, 'range_requests', true));

        return $processor;
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function downloadResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return $this->responses()->download($request, $path, $downloadName, $directory, $disk, $headers);
    }

    /**
     * @return array<string, list<string>>
     */
    public function duplicates(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return array_map(
            array_values(...),
            PathwiseFacade::duplicates($this->path($directory, $disk), $algorithm),
        );
    }

    public function ensureRuntimeDirectories(int $permissions = 0775): void
    {
        foreach ($this->runtimeDirectories() as $directory) {
            $this->at($directory)->directory()->create($permissions);
        }
    }

    public function exists(string $path, ?string $disk = null): bool
    {
        return FlysystemHelper::has($this->path($path, $disk));
    }

    public function finalizeChunkUpload(string $uploadId, ?string $directory = null, ?string $disk = null): string
    {
        return $this->uploadRequests()->finalizeChunkUpload($uploadId, $directory, $disk);
    }

    /**
     * @return array<string, list<string>>
     */
    public function index(string $directory = '', string $algorithm = 'sha256', ?string $disk = null): array
    {
        return array_map(
            array_values(...),
            PathwiseFacade::index($this->path($directory, $disk), $algorithm),
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function inlineResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
    ): Response {
        return $this->responses()->inline($request, $path, $downloadName, $directory, $disk, $headers);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listContents(string $path = '', bool $deep = true, ?string $disk = null): array
    {
        return FlysystemHelper::listContents($this->path($path, $disk), $deep);
    }

    public function localPath(string $path = '', ?string $disk = null): string
    {
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        $resolvedDisk = $this->filesystemTargetDisk($disk);
        $root = $this->localDiskRoot($resolvedDisk);
        if ($root === null) {
            throw new \InvalidArgumentException(sprintf('Filesystem disk "%s" is not a local disk.', $resolvedDisk));
        }

        $relativePath = trim(str_replace('\\', '/', $path), '/');

        return $relativePath === ''
            ? $root
            : PathHelper::join($root, $relativePath);
    }

    public function logs(string $path = ''): string
    {
        return $this->paths->logs($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function makeDirectory(string $path = '', ?string $disk = null, array $config = []): void
    {
        FlysystemHelper::createDirectory($this->path($path, $disk), $config);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(string $path, bool $humanReadableSize = false, ?string $disk = null): ?array
    {
        return $this->at($path, $disk)->metadata($humanReadableSize);
    }

    public function mimeType(string $path, ?string $disk = null): ?string
    {
        return FlysystemHelper::mimeType($this->path($path, $disk));
    }

    /**
     * @param array<string, mixed> $config
     */
    public function move(
        string $source,
        string $destination,
        ?string $sourceDisk = null,
        ?string $destinationDisk = null,
        array $config = [],
    ): void {
        FlysystemHelper::move(
            $this->path($source, $sourceDisk),
            $this->path($destination, $destinationDisk),
            $config,
        );
    }

    public function path(string $path = '', ?string $disk = null): string
    {
        if ($path !== '' && PathHelper::isAbsolute($path)) {
            return PathHelper::normalize($path);
        }

        return $this->diskPath($this->filesystemTargetDisk($disk), $path);
    }

    public function paths(): PathManager
    {
        return $this->paths;
    }

    public function policy(): PolicyEngine
    {
        return PathwiseFacade::policy();
    }

    /**
     * @return array{uploadId: string, receivedChunks: int, totalChunks: int, isComplete: bool}
     */
    public function processChunkUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $uploadId = null,
        ?int $chunkIndex = null,
        ?int $totalChunks = null,
        ?string $originalFilename = null,
        ?string $directory = null,
        ?string $disk = null,
    ): array {
        return $this->uploadRequests()->processChunkUploadRequest(
            $request,
            $field,
            $uploadId,
            $chunkIndex,
            $totalChunks,
            $originalFilename,
            $directory,
            $disk,
        );
    }

    public function processUploadRequest(
        Request $request,
        string $field = 'file',
        ?string $directory = null,
        ?string $disk = null,
    ): string {
        return $this->uploadRequests()->processUploadRequest($request, $field, $directory, $disk);
    }

    public function public(string $path = ''): string
    {
        return $this->paths->public($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function publicUrl(string $path, ?string $disk = null, array $config = []): string
    {
        return FlysystemHelper::publicUrl($this->path($path, $disk), $config);
    }

    public function queue(string $queueFilePath): FileJobQueue
    {
        return PathwiseFacade::queue($this->baseRelativePath($queueFilePath));
    }

    public function read(string $path, ?string $disk = null): string
    {
        return FlysystemHelper::read($this->path($path, $disk));
    }

    public function readStream(string $path, ?string $disk = null): mixed
    {
        return FlysystemHelper::readStream($this->path($path, $disk));
    }

    public function resources(string $path = ''): string
    {
        return $this->paths->resources($path);
    }

    public function responses(): FilesystemResponseFactory
    {
        return new FilesystemResponseFactory($this);
    }

    /**
     * @return array{deleted: list<string>, kept: list<string>}
     */
    public function retain(
        string $directory = '',
        ?int $keepLast = null,
        ?int $maxAgeDays = null,
        string $sortBy = 'mtime',
        ?string $disk = null,
    ): array {
        return PathwiseFacade::retain(
            $this->path($directory, $disk),
            $keepLast,
            $maxAgeDays,
            $sortBy,
        );
    }

    public function routes(string $path = ''): string
    {
        return $this->paths->routes($path);
    }

    public function sessions(string $path = ''): string
    {
        return $this->paths->sessions($path);
    }

    public function setVisibility(string $path, string $visibility, ?string $disk = null): void
    {
        FlysystemHelper::setVisibility($this->path($path, $disk), $visibility);
    }

    public function size(string $path, ?string $disk = null): int
    {
        return FlysystemHelper::size($this->path($path, $disk));
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public function snapshot(string $path = '', bool $recursive = true, ?string $disk = null): array
    {
        return PathwiseFacade::snapshot($this->path($path, $disk), $recursive);
    }

    public function storage(string $path = ''): string
    {
        return $this->paths->storage($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiresAt, ?string $disk = null, array $config = []): string
    {
        return FlysystemHelper::temporaryUrl($this->path($path, $disk), $expiresAt, $config);
    }

    public function upload(?string $directory = null, ?string $disk = null): UploadProcessor
    {
        $config = $this->arrayConfig('uploads');
        $processor = PathwiseFacade::upload();
        $diskName = $this->filesystemTargetDisk(
            $disk,
            $this->stringArrayValue($config, 'disk', 'uploads'),
        );

        $processor->setDirectorySettings(
            $this->operationPath($diskName, $directory ?? $this->stringArrayValue($config, 'directory')),
            $this->boolArrayValue($config, 'use_date_directories', false),
            $this->nullableBasePath($config['temp_directory'] ?? null),
        );
        $processor->setExtensionPolicy(
            $this->stringList($config['allowed_extensions'] ?? []),
            $this->stringList($config['blocked_extensions'] ?? []),
        );
        $processor->setChunkLimits(
            $this->intArrayValue($config, 'max_chunk_count', 0),
            $this->intArrayValue($config, 'max_chunk_size', 0),
        );

        $validationProfile = $config['validation_profile'] ?? null;
        if (is_string($validationProfile) && $validationProfile !== '') {
            $processor->setValidationProfile($validationProfile);
        } else {
            $processor->setValidationSettings(
                $this->stringList($config['allowed_file_types'] ?? []),
                $this->intArrayValue($config, 'max_file_size', 30720),
            );
        }

        $processor->setImageValidationSettings(
            $this->intArrayValue($config, 'max_image_width', 0),
            $this->intArrayValue($config, 'max_image_height', 0),
        );
        $processor->setNamingStrategy($this->stringArrayValue($config, 'naming_strategy', 'hash'));
        $processor->setRequireMalwareScan($this->boolArrayValue($config, 'require_malware_scan', false));
        $processor->setStrictContentTypeValidation($this->boolArrayValue($config, 'strict_content_type_validation', false));

        return $processor;
    }

    public function uploadRequests(): FilesystemUploadRequestHandler
    {
        return new FilesystemUploadRequestHandler($this);
    }

    public function uploads(string $path = ''): string
    {
        return $this->paths->uploads($path);
    }

    public function visibility(string $path, ?string $disk = null): string
    {
        return FlysystemHelper::visibility($this->path($path, $disk));
    }

    /**
     * @return array<string, array{mtime: int, size: int}>
     */
    public function watch(
        string $path,
        callable $onChange,
        int $durationSeconds = 5,
        int $intervalMilliseconds = 500,
        bool $recursive = true,
        ?string $disk = null,
    ): array {
        return PathwiseFacade::watch(
            $this->path($path, $disk),
            $onChange,
            $durationSeconds,
            $intervalMilliseconds,
            $recursive,
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function write(string $path, string $contents, ?string $disk = null, array $config = []): void
    {
        FlysystemHelper::write($this->path($path, $disk), $contents, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function writeStream(string $path, mixed $stream, ?string $disk = null, array $config = []): void
    {
        FlysystemHelper::writeStream($this->path($path, $disk), $stream, $config);
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function xAccelRedirectResponse(
        Request $request,
        string $internalPath,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        return $this->responses()->xAccelRedirect(
            $request,
            $internalPath,
            $path,
            $downloadName,
            $directory,
            $disk,
            $headers,
            $inline,
        );
    }

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function xSendfileResponse(
        Request $request,
        string $path,
        ?string $downloadName = null,
        ?string $directory = null,
        ?string $disk = null,
        array $headers = [],
        bool $inline = false,
    ): Response {
        return $this->responses()->xSendfile(
            $request,
            $path,
            $downloadName,
            $directory,
            $disk,
            $headers,
            $inline,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayConfig(string $key): array
    {
        return ValueNormalizer::associativeArray(
            $this->config->get('filesystem.' . $key, []),
        );
    }

    private function baseRelativePath(string $path): string
    {
        if ($path === '') {
            return $this->base();
        }

        if (PathHelper::isAbsolute($path) || PathHelper::hasScheme($path)) {
            return PathHelper::normalize($path);
        }

        return $this->base($path);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function boolArrayValue(array $config, string $key, bool $default): bool
    {
        $value = $config[$key] ?? $default;

        return match (true) {
            is_bool($value) => $value,
            is_int($value) => $value !== 0,
            is_string($value) => in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true),
            default => $default,
        };
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configuredFilesystems(): array
    {
        $configured = $this->config->get('filesystem.disks', []);
        if (!is_array($configured)) {
            return [];
        }

        $filesystems = [];

        foreach ($configured as $name => $config) {
            if (!is_string($name) || !is_array($config)) {
                continue;
            }

            $filesystems[$name] = ValueNormalizer::associativeArray($config);
        }

        return $filesystems;
    }

    private function defaultDisk(): string
    {
        $configured = $this->config->get('filesystem.default', 'local');

        return $this->resolveDisk(is_string($configured) && $configured !== '' ? $configured : 'local');
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function downloadAllowedRoots(array $config, string $disk): array
    {
        $roots = [];

        foreach ($this->stringList($config['allowed_roots'] ?? []) as $root) {
            if (PathHelper::isAbsolute($root)) {
                $roots[] = PathHelper::normalize($root);

                continue;
            }

            $roots[] = $this->operationPath($disk, $root);
        }

        return $roots;
    }

    private function filesystemTargetDisk(?string $candidate, string $default = ''): string
    {
        if (is_string($candidate) && $candidate !== '') {
            return $this->resolveDisk($candidate);
        }

        if ($default !== '') {
            return $this->resolveDisk($default);
        }

        return $this->defaultDisk();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function intArrayValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;

        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && is_numeric($value)
            ? (int) $value
            : $default;
    }

    private function localDiskRoot(string $disk): ?string
    {
        $config = $this->configuredFilesystems()[$disk] ?? null;
        if ($config === null || $this->stringArrayValue($config, 'driver', 'local') !== 'local') {
            return null;
        }

        $root = $config['root'] ?? null;
        if (!is_string($root) || $root === '') {
            return null;
        }

        return PathHelper::isAbsolute($root)
            ? PathHelper::normalize($root)
            : $this->paths->base($root);
    }

    private function mountConfiguredFilesystems(): void
    {
        FlysystemHelper::reset();

        foreach ($this->configuredFilesystems() as $name => $config) {
            if ($name === '') {
                continue;
            }

            $disk = $this->resolveDisk($name);
            $this->filesystems[$disk] = PathwiseFacade::mountStorage(
                $disk,
                $this->normalizeFilesystemConfig($config),
            );
        }

        $defaultDisk = $this->defaultDisk();
        if (isset($this->filesystems[$defaultDisk])) {
            FlysystemHelper::setDefaultFilesystem($this->filesystems[$defaultDisk]);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeFilesystemConfig(array $config): array
    {
        $root = $config['root'] ?? null;
        if (
            is_string($root)
            && $root !== ''
            && !PathHelper::isAbsolute($root)
            && $this->stringArrayValue($config, 'driver', 'local') === 'local'
        ) {
            $config['root'] = $this->paths->base($root);
        }

        return $config;
    }

    private function nullableBasePath(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return PathHelper::isAbsolute($value)
            ? PathHelper::normalize($value)
            : $this->paths->base($value);
    }

    private function operationPath(string $disk, string $path = ''): string
    {
        $root = $this->localDiskRoot($disk);
        if ($root !== null) {
            return $path === ''
                ? $root
                : PathHelper::join($root, $path);
        }

        return $this->diskPath($disk, $path);
    }

    private function resolveDisk(?string $name): string
    {
        $candidate = strtolower(trim($name ?? ''));

        return $candidate !== ''
            ? $candidate
            : $this->defaultDisk();
    }

    /**
     * @return list<string>
     */
    private function runtimeDirectories(): array
    {
        $directories = $this->paths->runtimeDirectories();

        foreach ($this->configuredFilesystems() as $config) {
            $root = $config['root'] ?? null;
            if (!is_string($root) || $root === '') {
                continue;
            }

            $directories[] = PathHelper::isAbsolute($root)
                ? PathHelper::normalize($root)
                : $this->paths->base($root);
        }

        $uploadDirectory = $this->stringArrayValue($this->arrayConfig('uploads'), 'directory');
        if ($uploadDirectory !== '') {
            $directories[] = $this->path(
                $uploadDirectory,
                $this->stringArrayValue($this->arrayConfig('uploads'), 'disk', 'uploads'),
            );
        }

        $downloadDirectory = $this->stringArrayValue($this->arrayConfig('downloads'), 'directory');
        if ($downloadDirectory !== '') {
            $directories[] = $this->path(
                $downloadDirectory,
                $this->stringArrayValue($this->arrayConfig('downloads'), 'disk', 'uploads'),
            );
        }

        return array_values(array_unique($directories));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function stringArrayValue(array $config, string $key, string $default = ''): string
    {
        $value = $config[$key] ?? $default;

        return is_string($value)
            ? $value
            : $default;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (!is_string($item) || $item === '') {
                continue;
            }

            $strings[] = $item;
        }

        return array_values(array_unique($strings));
    }
}
