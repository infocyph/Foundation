<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\FilesystemMalwareScannerResolver;
use Infocyph\Foundation\Filesystem\FilesystemPublicFileResolver;
use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\FilesystemUploadRequestHandler;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Foundation;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use Psr\Container\ContainerInterface;


/** @return array{Application,string} */
function foundationFilesystemApp(): array
{
    $basePath = sys_get_temp_dir() . '/foundation-filesystem-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    return [Foundation::web([
        'base_path' => $basePath,
        '_config_cache' => false,
        'router' => ['cache' => false],
    ]), $basePath];
}

it('preserves conditional and ranged local files as native Webrick file bodies', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $transfers = $app->make(FilesystemTransferFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/http-' . bin2hex(random_bytes(8));
    $relativePath = $directory . '/payload.txt';
    $contents = 'Foundation ranged download bridge';

    $disk->write($relativePath, $contents);

    try {
        $rangeRequest = Request::fake(
            headers: ['Host' => 'localhost', 'Range' => 'bytes=11-16'],
            uri: 'http://localhost/download',
        );
        $rangeResponse = $responses->download($rangeRequest, $relativePath, directory: $directory, disk: 'uploads');

        expect($rangeResponse->getStatusCode())->toBe(206)
            ->and($rangeResponse->getHeaderLine('Content-Range'))->toBe('bytes 11-16/' . strlen($contents))
            ->and($rangeResponse->getHeaderLine('Accept-Ranges'))->toBe('bytes')
            ->and($rangeResponse->getHeaderLine('Content-Disposition'))->toContain('attachment;')
            ->and($rangeResponse->getProducer())->toBeNull();

        $rangeBody = $rangeResponse->getFileBody();
        expect($rangeBody)->toBeInstanceOf(FileBody::class)
            ->and($rangeBody?->offset())->toBe(11)
            ->and($rangeBody?->length())->toBe(6)
            ->and($rangeBody?->read(6))->toBe(substr($contents, 11, 6));

        $inlineResponse = $responses->inline(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/inline'),
            $relativePath,
            directory: $directory,
            disk: 'uploads',
        );
        expect($inlineResponse->getStatusCode())->toBe(200)
            ->and($inlineResponse->getHeaderLine('Content-Disposition'))->toContain('inline;')
            ->and($inlineResponse->getFileBody())->toBeInstanceOf(FileBody::class);

        $headResponse = $responses->download(
            Request::fake(headers: ['Host' => 'localhost'], method: 'HEAD', uri: 'http://localhost/download'),
            $relativePath,
            directory: $directory,
            disk: 'uploads',
        );
        expect($headResponse->getStatusCode())->toBe(200)
            ->and($headResponse->getBodySize())->toBe(0)
            ->and($headResponse->getHeaderLine('Content-Length'))->toBe((string) strlen($contents));

        $manifest = $transfers->download($directory, 'uploads')
            ->prepareDownload($storage->path($relativePath, 'uploads'));
        $notModifiedResponse = $responses->download(
            Request::fake(
                headers: ['Host' => 'localhost', 'If-None-Match' => $manifest->etag],
                uri: 'http://localhost/download',
            ),
            $relativePath,
            directory: $directory,
            disk: 'uploads',
        );
        expect($notModifiedResponse->getStatusCode())->toBe(304)
            ->and($notModifiedResponse->getHeaderLine('ETag'))->toBe($manifest->etag);
    } finally {
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($basePath);
    }
});

it('exposes context-routed Pathwise responses as Webrick chunk iterables without direct output', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/stream-' . bin2hex(random_bytes(8));
    $relativePath = $directory . '/payload.txt';
    $contents = str_repeat('stream-body-', 64);
    $disk->write($relativePath, $contents);

    try {
        $contextPath = $storage->path($relativePath, 'uploads');
        $response = $responses->download(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/stream'),
            $contextPath,
            disk: 'uploads',
        );

        expect($response->getFileBody())->toBeNull()
            ->and($response->isStreaming())->toBeTrue();

        $producer = $response->getProducer();
        expect($producer)->not->toBeNull();
        $body = '';
        foreach ($producer() as $chunk) {
            $body .= $chunk;
        }
        expect($body)->toBe($contents);
    } finally {
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($basePath);
    }
});

it('handles normal and chunked upload requests through Pathwise UploadSource', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $uploads = $app->make(FilesystemUploadRequestHandler::class);
    $storage = $app->make(StorageRegistry::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/uploads-' . bin2hex(random_bytes(8));

    $uploadTemp = tempnam(sys_get_temp_dir(), 'foundation-source-');
    $chunkOne = tempnam(sys_get_temp_dir(), 'foundation-source-');
    $chunkTwo = tempnam(sys_get_temp_dir(), 'foundation-source-');
    if ($uploadTemp === false || $chunkOne === false || $chunkTwo === false) {
        throw new RuntimeException('Unable to allocate upload temp files.');
    }
    file_put_contents($uploadTemp, 'single upload body');
    file_put_contents($chunkOne, 'chunk-one-');
    file_put_contents($chunkTwo, 'chunk-two');

    try {
        $uploadRequest = Request::fake(headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload')
            ->withUploadedFiles(['file' => [
                'tmp_name' => $uploadTemp,
                'size' => filesize($uploadTemp) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'single.txt',
                'type' => 'text/plain',
            ]]);
        $storedPath = $uploads->processUploadRequest($uploadRequest, directory: $directory, disk: 'uploads');
        [$storedDisk, $storedLocation] = $storage->context()->resolve($storedPath, 'uploads');
        expect($storedPath)->toStartWith('uploads://' . $directory . '/')
            ->and($storedDisk->read($storedLocation))->toBe('single upload body');

        $first = Request::fake(
            post: ['uploadId' => 'bridge-upload', 'chunkIndex' => 0, 'totalChunks' => 2, 'originalFilename' => 'chunked.txt'],
            headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload/chunk',
        )->withUploadedFiles(['file' => [
            'tmp_name' => $chunkOne, 'size' => filesize($chunkOne) ?: 0, 'error' => UPLOAD_ERR_OK,
            'name' => 'chunked.txt', 'type' => 'text/plain',
        ]]);
        $second = Request::fake(
            post: ['upload_id' => 'bridge-upload', 'chunk_index' => 1, 'total_chunks' => 2, 'original_filename' => 'chunked.txt'],
            headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload/chunk',
        )->withUploadedFiles(['file' => [
            'tmp_name' => $chunkTwo, 'size' => filesize($chunkTwo) ?: 0, 'error' => UPLOAD_ERR_OK,
            'name' => 'chunked.txt', 'type' => 'text/plain',
        ]]);

        $firstState = $uploads->processChunkUploadRequest($first, directory: $directory, disk: 'uploads');
        $secondState = $uploads->processChunkUploadRequest($second, directory: $directory, disk: 'uploads');
        $finalized = $uploads->finalizeChunkUpload('bridge-upload', $directory, 'uploads');
        [$finalDisk, $finalLocation] = $storage->context()->resolve($finalized, 'uploads');

        expect($firstState->complete)->toBeFalse()
            ->and($secondState->complete)->toBeTrue()
            ->and($finalized)->toStartWith('uploads://' . $directory . '/')
            ->and($finalDisk->read($finalLocation))->toBe('chunk-one-chunk-two');
    } finally {
        foreach ([$uploadTemp, $chunkOne, $chunkTwo] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        if ($disk->directoryExists($directory)) {
            $disk->deleteDirectory($directory);
        }
        foundationFilesystemRemoveDirectory($basePath);
    }
});

function foundationFilesystemRemoveDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

it('prepares Pathwise download metadata once for full and stale If-Range responses', function (): void {
    $basePath = sys_get_temp_dir() . '/foundation-download-probe-' . bin2hex(random_bytes(5));
    $storageRoot = $basePath . '/storage';
    mkdir($storageRoot, 0775, true);

    $filesystem = new FoundationFilesystemMetadataProbe(
        new LocalFilesystemAdapter($storageRoot),
    );
    $config = new ConfigRepository([
        'app' => ['base_path' => $basePath],
        'filesystem' => [
            'default' => 'probe',
            'disks' => [
                'probe' => ['driver' => 'probe'],
            ],
            'downloads' => [
                'disk' => 'probe',
                'directory' => '',
                'allowed_roots' => [],
                'allowed_extensions' => [],
                'blocked_extensions' => [],
                'block_hidden_files' => true,
                'chunk_size' => 8192,
                'default_name' => 'download.bin',
                'force_attachment' => true,
                'max_size' => 0,
                'range_requests' => true,
            ],
            'uploads' => [
                'malware_scan' => ['mode' => 'off'],
            ],
            'public_files' => [
                'root' => 'public',
                'symlink_policy' => 'reject',
            ],
        ],
    ]);
    $paths = new PathManager($basePath);
    $storage = new StorageRegistry(
        $config,
        $paths,
        ['probe' => static fn(array $definition): Filesystem => $filesystem],
    );
    $container = new class implements ContainerInterface {
        public function get(string $id): never
        {
            throw new LogicException(sprintf('No test service "%s" is registered.', $id));
        }

        public function has(string $id): bool
        {
            return false;
        }
    };
    $transfers = new FilesystemTransferFactory(
        $config,
        $paths,
        $storage,
        new FilesystemMalwareScannerResolver($config, $container),
    );
    $responses = new FilesystemResponseFactory(
        $config,
        $transfers,
        $storage,
        new FilesystemPublicFileResolver($config, $paths),
    );
    $filesystem->write('payload.txt', 'runtime Pathwise preparation probe');

    try {
        $filesystem->resetMetadataCounts();
        $full = $responses->download(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download'),
            'payload.txt',
            disk: 'probe',
        );

        expect($full->getStatusCode())->toBe(200)
            ->and($filesystem->fileSizeReads)->toBe(1)
            ->and($filesystem->mimeTypeReads)->toBe(1)
            ->and($filesystem->lastModifiedReads)->toBe(1);

        $filesystem->resetMetadataCounts();
        $stale = $responses->download(
            Request::fake(
                headers: [
                    'Host' => 'localhost',
                    'Range' => 'bytes=0-6',
                    'If-Range' => '"stale-validator"',
                ],
                uri: 'http://localhost/download',
            ),
            'payload.txt',
            disk: 'probe',
        );

        expect($stale->getStatusCode())->toBe(200)
            ->and($filesystem->fileSizeReads)->toBe(1)
            ->and($filesystem->mimeTypeReads)->toBe(1)
            ->and($filesystem->lastModifiedReads)->toBe(1);

        $filesystem->resetMetadataCounts();
        $partial = $responses->download(
            Request::fake(
                headers: ['Host' => 'localhost', 'Range' => 'bytes=0-6'],
                uri: 'http://localhost/download',
            ),
            'payload.txt',
            disk: 'probe',
        );

        expect($partial->getStatusCode())->toBe(206)
            ->and($filesystem->fileSizeReads)->toBe(2)
            ->and($filesystem->mimeTypeReads)->toBe(2)
            ->and($filesystem->lastModifiedReads)->toBe(2);
    } finally {
        foundationFilesystemRemoveDirectory($basePath);
    }
});

final class FoundationFilesystemMetadataProbe extends Filesystem
{
    public int $fileSizeReads = 0;

    public int $lastModifiedReads = 0;

    public int $mimeTypeReads = 0;

    public function fileSize(string $path): int
    {
        ++$this->fileSizeReads;

        return parent::fileSize($path);
    }

    public function lastModified(string $path): int
    {
        ++$this->lastModifiedReads;

        return parent::lastModified($path);
    }

    public function mimeType(string $path): string
    {
        ++$this->mimeTypeReads;

        return parent::mimeType($path);
    }

    public function resetMetadataCounts(): void
    {
        $this->fileSizeReads = 0;
        $this->lastModifiedReads = 0;
        $this->mimeTypeReads = 0;
    }
}
