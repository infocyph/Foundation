<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Webrick\Request\Request;

beforeEach(function (): void {
    if (!class_exists(PathwiseFacade::class)) {
        $this->markTestSkipped('Install the filesystem module to run Pathwise integration tests.');
    }
});

/**
 * @return array{Application, string}
 */
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

it('streams download responses with conditional and range handling through foundation', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $files = $app->files();
    $directory = 'tests/http-' . uniqid('', true);
    $relativePath = $directory . '/payload.txt';
    $contents = 'Foundation ranged download bridge';

    $files->write($relativePath, $contents, 'uploads');

    try {
        $rangeRequest = Request::fake(
            headers: [
                'Host' => 'localhost',
                'Range' => 'bytes=11-16',
            ],
            uri: 'http://localhost/download',
        );
        $rangeResponse = $files->downloadResponse($rangeRequest, $relativePath, directory: $directory, disk: 'uploads');

        expect($rangeResponse->getStatusCode())->toBe(206);
        expect($rangeResponse->getHeaderLine('Content-Range'))->toBe('bytes 11-16/' . strlen($contents));
        expect($rangeResponse->getHeaderLine('Accept-Ranges'))->toBe('bytes');
        expect($rangeResponse->getHeaderLine('Content-Disposition'))->toContain('attachment;');

        $producer = $rangeResponse->getProducer();

        expect($producer)->not->toBeNull();

        ob_start();
        $producer();
        $rangeBody = ob_get_clean();

        expect($rangeBody)->toBe(substr($contents, 11, 6));

        $inlineRequest = Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/inline');
        $inlineResponse = $files->inlineResponse($inlineRequest, $relativePath, directory: $directory, disk: 'uploads');

        expect($inlineResponse->getStatusCode())->toBe(200);
        expect($inlineResponse->getHeaderLine('Content-Disposition'))->toContain('inline;');

        $manifest = $files->download($directory, 'uploads')->prepareDownload($files->localPath($relativePath, 'uploads'));
        $notModifiedRequest = Request::fake(
            headers: [
                'Host' => 'localhost',
                'If-None-Match' => $manifest['etag'],
            ],
            uri: 'http://localhost/download',
        );
        $notModifiedResponse = $files->downloadResponse($notModifiedRequest, $relativePath, directory: $directory, disk: 'uploads');

        expect($notModifiedResponse->getStatusCode())->toBe(304);
        expect($notModifiedResponse->getHeaderLine('ETag'))->toBe($manifest['etag']);

        $staleRangeRequest = Request::fake(
            headers: [
                'Host' => 'localhost',
                'If-Range' => 'Sat, 01 Jan 2000 00:00:00 GMT',
                'Range' => 'bytes=0-4',
            ],
            uri: 'http://localhost/download',
        );
        $staleRangeResponse = $files->downloadResponse($staleRangeRequest, $relativePath, directory: $directory, disk: 'uploads');

        expect($staleRangeResponse->getStatusCode())->toBe(200);
        expect($staleRangeResponse->hasHeader('Content-Range'))->toBeFalse();
    } finally {
        $files->deleteDirectory($directory, 'uploads');
        foundationFilesystemRemoveDirectory($basePath);
    }
});

it('handles upload requests, chunked uploads, offload responses, and pathwise utilities through foundation', function (): void {
    [$app, $basePath] = foundationFilesystemApp();
    $app->boot();
    $files = $app->files();
    $directory = 'tests/uploads-' . uniqid('', true);
    $queuePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . '/foundation-files-queue-' . bin2hex(random_bytes(5)) . '.json';
    $auditPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . '/foundation-files-audit-' . bin2hex(random_bytes(5)) . '.log';

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
            ->withUploadedFiles([
                'file' => [
                    'tmp_name' => $uploadTemp,
                    'size' => filesize($uploadTemp) ?: 0,
                    'error' => UPLOAD_ERR_OK,
                    'name' => 'single.txt',
                    'type' => 'text/plain',
                ],
            ]);

        $storedPath = $files->processUploadRequest($uploadRequest, directory: $directory, disk: 'uploads');

        expect($storedPath)->toStartWith($app->uploadsPath($directory));
        expect($files->exists($directory . '/' . basename($storedPath), 'uploads'))->toBeTrue();

        $chunkRequestOne = Request::fake(
            post: [
                'uploadId' => 'bridge-upload',
                'chunkIndex' => 0,
                'totalChunks' => 2,
                'originalFilename' => 'chunked.txt',
            ],
            headers: ['Host' => 'localhost'],
            method: 'POST',
            uri: 'http://localhost/upload/chunk',
        )->withUploadedFiles([
            'file' => [
                'tmp_name' => $chunkOne,
                'size' => filesize($chunkOne) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'chunked.txt',
                'type' => 'text/plain',
            ],
        ]);
        $chunkRequestTwo = Request::fake(
            post: [
                'upload_id' => 'bridge-upload',
                'chunk_index' => 1,
                'total_chunks' => 2,
                'original_filename' => 'chunked.txt',
            ],
            headers: ['Host' => 'localhost'],
            method: 'POST',
            uri: 'http://localhost/upload/chunk',
        )->withUploadedFiles([
            'file' => [
                'tmp_name' => $chunkTwo,
                'size' => filesize($chunkTwo) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'chunked.txt',
                'type' => 'text/plain',
            ],
        ]);

        $firstChunk = $files->processChunkUploadRequest($chunkRequestOne, directory: $directory, disk: 'uploads');
        $secondChunk = $files->processChunkUploadRequest($chunkRequestTwo, directory: $directory, disk: 'uploads');
        $finalizedPath = $files->finalizeChunkUpload('bridge-upload', $directory, 'uploads');

        expect($firstChunk['isComplete'])->toBeFalse();
        expect($secondChunk['isComplete'])->toBeTrue();
        expect(file_get_contents($finalizedPath))->toBe('chunk-one-chunk-two');

        $sourceRelativePath = $directory . '/offload.txt';
        $files->write($sourceRelativePath, 'offload-body', 'uploads');

        $xSendfileResponse = $files->xSendfileResponse(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/private'),
            $sourceRelativePath,
            downloadName: 'private.txt',
            directory: $directory,
            disk: 'uploads',
        );
        $xAccelResponse = $files->xAccelRedirectResponse(
            Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/private'),
            '/internal/private/offload.txt',
            $sourceRelativePath,
            downloadName: 'private.txt',
            directory: $directory,
            disk: 'uploads',
        );

        expect($xSendfileResponse->getHeaderLine('X-Sendfile'))->toBe($files->localPath($sourceRelativePath, 'uploads'));
        expect($xSendfileResponse->getHeaderLine('Content-Disposition'))->toContain('attachment;');
        expect($xAccelResponse->getHeaderLine('X-Accel-Redirect'))->toBe('/internal/private/offload.txt');

        $snapshotBefore = $files->snapshot($directory, disk: 'uploads');
        $files->write($directory . '/duplicate-a.txt', 'same', 'uploads');
        $files->write($directory . '/duplicate-b.txt', 'same', 'uploads');
        $snapshotAfter = $files->snapshot($directory, disk: 'uploads');
        $duplicates = $files->duplicates($directory, disk: 'uploads');
        $index = $files->index($directory, disk: 'uploads');
        $queue = $files->queue($queuePath);
        $queueId = $queue->enqueue('download.sync', ['path' => $sourceRelativePath]);
        $auditTrail = $files->audit($auditPath);
        $policy = $files->policy();

        expect($files->diffSnapshots($snapshotBefore, $snapshotAfter)['created'])->not->toBeEmpty();
        expect($duplicates)->not->toBeEmpty();
        expect($index)->not->toBeEmpty();
        expect($queue->stats()['pending'])->toBe(1);
        expect($queueId)->not->toBe('');
        expect($auditTrail)->toBeObject();
        expect($policy)->toBeObject();
    } finally {
        if (is_file($uploadTemp)) {
            unlink($uploadTemp);
        }

        if (is_file($chunkOne)) {
            unlink($chunkOne);
        }

        if (is_file($chunkTwo)) {
            unlink($chunkTwo);
        }

        $files->deleteDirectory($directory, 'uploads');

        if (is_file($queuePath)) {
            unlink($queuePath);
        }

        if (is_file($auditPath)) {
            unlink($auditPath);
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
