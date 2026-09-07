<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\FilesystemUploadRequestHandler;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;

beforeEach(function (): void {
    if (!class_exists(PathwiseFacade::class)) {
        $this->markTestSkipped('Install the filesystem module to run Pathwise integration tests.');
    }
});

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
    $directory = 'tests/http-' . uniqid('', true);
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

        $manifest = $transfers->download($directory, 'uploads')->prepareDownload($storage->localPath($relativePath, 'uploads'));
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

it('exposes mounted Pathwise responses as Webrick chunk iterables without direct output', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/stream-' . uniqid('', true);
    $relativePath = $directory . '/payload.txt';
    $contents = str_repeat('stream-body-', 64);
    $disk->write($relativePath, $contents);

    try {
        $mountedPath = $storage->path($relativePath, 'uploads');
        $response = $responses->download(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/stream'),
            $mountedPath,
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

it('handles normal and chunked upload requests through the dedicated upload bridge', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $uploads = $app->make(FilesystemUploadRequestHandler::class);
    $storage = $app->make(StorageRegistry::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/uploads-' . uniqid('', true);

    $uploadTemp = tempnam(sys_get_temp_dir(), 'foundation-upload-');
    $chunkOne = tempnam(sys_get_temp_dir(), 'foundation-chunk-');
    $chunkTwo = tempnam(sys_get_temp_dir(), 'foundation-chunk-');
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
        expect($storedPath)->toStartWith($app->uploadsPath($directory));

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

        expect($firstState->complete)->toBeFalse()
            ->and($secondState->complete)->toBeTrue()
            ->and(file_get_contents($finalized))->toBe('chunk-one-chunk-two');
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
