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
use Infocyph\Pathwise\StreamHandler\MalwareScanRequest;
use Infocyph\Pathwise\StreamHandler\MalwareScanVerdict;
use Infocyph\Pathwise\StreamHandler\MalwareScannerInterface;
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

it('honors original Runwire cancellation during nonlocal lazy response advancement', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/lazy-owner-' . bin2hex(random_bytes(6));
    $path = $directory . '/download.txt';
    $disk->write($path, str_repeat('chunk-owner-', 1800));

    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);
    try {
        $request = \Infocyph\Runwire\RequestContext::create($runtime);
        try {
            $response = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
                $request,
                null,
                static fn() => $responses->download(
                    Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download'),
                    $storage->path($path, 'uploads'),
                    disk: 'uploads',
                ),
                $runtime,
            );
            expect($response->isStreaming())->toBeTrue();
            $producer = $response->getProducer();
            expect($producer)->not->toBeNull();
            $request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
            expect(fn() => $producer()->current())->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
        } finally {
            $request->complete();
        }

        $request = \Infocyph\Runwire\RequestContext::create($runtime);
        try {
            $response = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
                $request,
                null,
                static fn() => $responses->download(
                    Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download'),
                    $storage->path($path, 'uploads'),
                    disk: 'uploads',
                ),
                $runtime,
            );
            $producer = $response->getProducer();
            $chunks = $producer();
            expect($chunks->current())->toBeString()->not->toBe('');
            $request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
            expect(fn() => $chunks->next())->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
            unset($chunks);
        } finally {
            $request->complete();
        }
    } finally {
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($root);
    }
});

it('isolates lazy streamed downloads across Fibers and rejects an abandoned completed owner', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/lazy-fibers-' . bin2hex(random_bytes(6));
    $file = $directory . '/transfer.bin';
    $disk->write($file, str_repeat('isolated-stream-', 10_000));

    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);
    $initialBinding = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current();
    $first = \Infocyph\Runwire\RequestContext::create($runtime);
    $second = \Infocyph\Runwire\RequestContext::create($runtime);
    $abandoned = \Infocyph\Runwire\RequestContext::create($runtime);
    try {
        $responseFor = static fn($owner) => \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
            $owner,
            null,
            static fn() => $responses->download(
                Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/transfer'),
                $storage->path($file, 'uploads'),
                disk: 'uploads',
            ),
            $runtime,
        );
        $one = $responseFor($first)->getProducer();
        $two = $responseFor($second)->getProducer();
        expect($one)->not->toBeNull()
            ->and($two)->not->toBeNull();

        $firstStream = $one();
        $secondStream = $two();
        $fiberOne = new Fiber(static function () use ($firstStream): void {
            expect($firstStream->current())->toBeString()->not->toBe('');
            Fiber::suspend();
            $firstStream->next();
        });
        $fiberTwo = new Fiber(static function () use ($secondStream): void {
            expect($secondStream->current())->toBeString()->not->toBe('');
            Fiber::suspend();
            $secondStream->next();
            expect($secondStream->current())->toBeString()->not->toBe('');
        });

        $fiberOne->start();
        $fiberTwo->start();
        $first->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
        expect(fn() => $fiberOne->resume())->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
        $fiberTwo->resume();
        expect($fiberTwo->isTerminated())->toBeTrue()
            ->and(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBe($initialBinding);

        $dead = $responseFor($abandoned)->getProducer();
        expect($dead)->not->toBeNull();
        $abandoned->complete();
        expect(fn() => $dead()->current())->toThrow(LogicException::class, 'Runwire request is completed');
        unset($firstStream, $secondStream, $dead);
    } finally {
        $first->complete();
        $second->complete();
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($root);
    }
});

it('restores the original borrowed Runwire context after a lazy stream read failure', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/lazy-read-error-' . bin2hex(random_bytes(6));
    $path = $directory . '/transfer.bin';
    $disk->write($path, str_repeat('read-failure-payload-', 20_000));

    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);
    $outer = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current();
    $request = \Infocyph\Runwire\RequestContext::create($runtime);
    try {
        $response = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
            $request,
            null,
            static fn() => $responses->download(
                Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/transfer'),
                $storage->path($path, 'uploads'),
                disk: 'uploads',
            ),
            $runtime,
        );
        $producer = $response->getProducer();
        expect($producer)->not->toBeNull();
        $chunks = $producer();
        expect($chunks->current())->toBeString()->not->toBe('');

        // The response producer has opened the source. Shorten that same
        // physical file after its first chunk to force actual read-time EOF.
        $local = $storage->localPath($path, 'uploads');
        expect(file_put_contents($local, ''))->toBe(0);
        expect(fn() => iterator_to_array($chunks, false))
            ->toThrow(\Infocyph\Pathwise\Exceptions\DownloadException::class, 'ended before');
        expect(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBe($outer);
        unset($chunks);
    } finally {
        $request->complete();
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($root);
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
    $chunkConflict = tempnam(sys_get_temp_dir(), 'foundation-source-');
    if ($uploadTemp === false || $chunkOne === false || $chunkTwo === false || $chunkConflict === false) {
        throw new RuntimeException('Unable to allocate upload temp files.');
    }
    file_put_contents($uploadTemp, 'single upload body');
    file_put_contents($chunkOne, 'chunk-one-');
    file_put_contents($chunkTwo, 'chunk-two');
    file_put_contents($chunkConflict, 'conflicting-chunk');

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
            post: ['uploadId' => 'bridge-upload', 'chunk_index' => '0', 'chunkIndex' => 0, 'totalChunks' => 2, 'originalFilename' => 'chunked.txt'],
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

        // Neither fractional/exponent notation nor overflow may be coerced into
        // a different chunk index before Pathwise receives the source.
        foreach (['1.5', '1e2', '-1', str_repeat('9', 40)] as $index) {
            $malformed = Request::fake(
                post: [
                    'uploadId' => 'bridge-malformed',
                    'chunkIndex' => $index,
                    'totalChunks' => 2,
                    'originalFilename' => 'chunked.txt',
                ],
                headers: ['Host' => 'localhost'],
                method: 'POST',
                uri: 'http://localhost/upload/chunk',
            )->withUploadedFiles(['file' => [
                'tmp_name' => $chunkOne,
                'size' => filesize($chunkOne) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'chunked.txt',
                'type' => 'text/plain',
            ]]);
            expect(fn() => $uploads->processChunkUploadRequest($malformed, directory: $directory, disk: 'uploads'))
                ->toThrow(InvalidArgumentException::class);
            expect(is_file($chunkOne))->toBeTrue();
        }

        // Both supported aliases must agree; invalid aliases cannot be masked by valid values.
        foreach ([
            ['chunk_index' => '1e2', 'chunkIndex' => 0],
            ['chunk_index' => 0, 'chunkIndex' => '1.5'],
            ['chunk_index' => 0, 'chunkIndex' => 1],
            ['chunk_index' => 0, 'chunkIndex' => true],
            ['chunk_index' => -1, 'chunkIndex' => 0],
            ['chunk_index' => str_repeat('9', 40), 'chunkIndex' => 0],
        ] as $metadata) {
            $malformed = Request::fake(
                post: ['uploadId' => 'bridge-conflicting', 'totalChunks' => 2, 'originalFilename' => 'chunked.txt'] + $metadata,
                headers: ['Host' => 'localhost'],
                method: 'POST',
                uri: 'http://localhost/upload/chunk',
            )->withUploadedFiles(['file' => [
                'tmp_name' => $chunkOne,
                'size' => filesize($chunkOne) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'chunked.txt',
                'type' => 'text/plain',
            ]]);
            expect(fn() => $uploads->processChunkUploadRequest($malformed, directory: $directory, disk: 'uploads'))
                ->toThrow(InvalidArgumentException::class);
            expect(is_file($chunkOne))->toBeTrue();
        }

        $firstState = $uploads->processChunkUploadRequest($first, directory: $directory, disk: 'uploads');

        // An existing chunk session cannot be repurposed under a different
        // filename after the first chunk has been durably accepted.
        $conflicting = Request::fake(
            post: [
                'uploadId' => 'bridge-upload',
                'chunkIndex' => 1,
                'totalChunks' => 2,
                'originalFilename' => 'different.txt',
            ],
            headers: ['Host' => 'localhost'],
            method: 'POST',
            uri: 'http://localhost/upload/chunk',
        )->withUploadedFiles(['file' => [
            'tmp_name' => $chunkConflict,
            'size' => filesize($chunkConflict) ?: 0,
            'error' => UPLOAD_ERR_OK,
            'name' => 'different.txt',
            'type' => 'text/plain',
        ]]);
        expect(fn() => $uploads->processChunkUploadRequest($conflicting, directory: $directory, disk: 'uploads'))
            ->toThrow(\Infocyph\Pathwise\Exceptions\UploadException::class, 'metadata does not match');

        $secondState = $uploads->processChunkUploadRequest($second, directory: $directory, disk: 'uploads');
        $finalized = $uploads->finalizeChunkUpload('bridge-upload', $directory, 'uploads');
        [$finalDisk, $finalLocation] = $storage->context()->resolve($finalized, 'uploads');

        expect($firstState->complete)->toBeFalse()
            ->and($secondState->complete)->toBeTrue()
            ->and($finalized)->toStartWith('uploads://' . $directory . '/')
            ->and($finalDisk->read($finalLocation))->toBe('chunk-one-chunk-two');
    } finally {
        foreach ([$uploadTemp, $chunkOne, $chunkTwo, $chunkConflict] as $path) {
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

it('blocks HTTP upload directory traversal before moving the source and permits a contained retry', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $uploads = $app->make(FilesystemUploadRequestHandler::class);
    $storage = $app->make(StorageRegistry::class);
    $disk = $storage->disk('uploads');
    $source = tempnam(sys_get_temp_dir(), 'foundation-contained-upload-');
    if ($source === false) {
        throw new RuntimeException('Unable to allocate contained upload fixture.');
    }
    file_put_contents($source, 'contained-body');

    try {
        $request = Request::fake(headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload')
            ->withUploadedFiles(['file' => [
                'tmp_name' => $source,
                'size' => filesize($source) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'safe.txt',
                'type' => 'text/plain',
            ]]);

        expect(fn() => $uploads->processUploadRequest($request, directory: '../outside', disk: 'uploads'))
            ->toThrow(InvalidArgumentException::class, 'traversal');
        expect(is_file($source))->toBeTrue();

        $stored = $uploads->processUploadRequest($request, directory: 'tests/contained', disk: 'uploads');
        [$storedDisk, $location] = $storage->context()->resolve($stored, 'uploads');
        expect($stored)->toStartWith('uploads://tests/contained/upload_')
            ->and($storedDisk->read($location))->toBe('contained-body');
    } finally {
        if (is_file($source)) {
            unlink($source);
        }
        if ($disk->directoryExists('tests/contained')) {
            $disk->deleteDirectory('tests/contained');
        }
        foundationFilesystemRemoveDirectory($root);
    }
});

it('fails closed on a malicious HTTP upload before publishing a Pathwise storage object', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/scanner-' . bin2hex(random_bytes(8));
    $temporary = tempnam(sys_get_temp_dir(), 'foundation-scanned-upload-');
    if ($temporary === false) {
        throw new RuntimeException('Unable to allocate scanned upload fixture.');
    }
    file_put_contents($temporary, 'untrusted-data-fixture');
    $cancelledTemp = null;

    $scanner = new class implements MalwareScannerInterface {
        public int $calls = 0;

        public bool $cancelDuringScan = false;

        public ?string $stagedPath = null;

        public ?string $stagedContents = null;

        public ?int $stagedSize = null;

        public ?string $clientFilename = null;

        #[\Override]
        public function scan(MalwareScanRequest $request): MalwareScanVerdict
        {
            ++$this->calls;
            $this->stagedPath = $request->localPath;
            $this->stagedContents = file_get_contents($request->localPath);
            $this->stagedSize = $request->size;
            $this->clientFilename = $request->clientFilename;

            if ($this->cancelDuringScan) {
                $borrowed = \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current();
                if ($borrowed === null) {
                    throw new LogicException('Expected a live borrowed Runwire request in the malware scanner.');
                }
                $borrowed->request->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);

                return MalwareScanVerdict::CLEAN;
            }

            return MalwareScanVerdict::MALICIOUS;
        }
    };
    $container = new class implements ContainerInterface {
        public ?MalwareScannerInterface $scanner = null;

        #[\Override]
        public function get(string $id): mixed
        {
            if ($id !== MalwareScannerInterface::class || $this->scanner === null) {
                throw new RuntimeException('Unknown application scanner binding.');
            }

            return $this->scanner;
        }

        #[\Override]
        public function has(string $id): bool
        {
            return $id === MalwareScannerInterface::class && $this->scanner !== null;
        }
    };
    $container->scanner = $scanner;
    $config = new ConfigRepository(['filesystem' => [
        'uploads' => ['malware_scan' => ['mode' => 'required', 'driver' => 'service']],
    ]]);
    $handler = new FilesystemUploadRequestHandler(new FilesystemTransferFactory(
        $config,
        $app->make(PathManager::class),
        $storage,
        new FilesystemMalwareScannerResolver($config, $container),
    ));

    try {
        $request = Request::fake(headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload')
            ->withUploadedFiles(['file' => [
                'tmp_name' => $temporary,
                'size' => filesize($temporary) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'malicious.txt',
                'type' => 'text/plain',
            ]]);

        expect(fn() => $handler->processUploadRequest($request, directory: $directory, disk: 'uploads'))
            ->toThrow(\Infocyph\Pathwise\Exceptions\UploadException::class, 'Malware scan rejected');
        expect($scanner->calls)->toBe(1)
            ->and($scanner->stagedPath)->not->toBeNull()
            ->and($scanner->stagedContents)->toBe('untrusted-data-fixture')
            ->and($scanner->stagedSize)->toBe(strlen('untrusted-data-fixture'))
            ->and($scanner->clientFilename)->toBeNull()
            ->and(is_file($scanner->stagedPath ?? ''))->toBeFalse()
            ->and($disk->listContents($directory)->toArray())->toBe([]);

        // Cancellation from inside a CLEAN scanner verdict still prevents
        // publication at Pathwise's post-scan native Runwire checkpoint.
        $scanner->cancelDuringScan = true;
        $cancelledTemp = tempnam(sys_get_temp_dir(), 'foundation-cancelled-upload-');
        if ($cancelledTemp === false) {
            throw new RuntimeException('Unable to allocate cancelled upload fixture.');
        }
        file_put_contents($cancelledTemp, 'cancel-during-scan');
        $cancelledRequest = Request::fake(headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload')
            ->withUploadedFiles(['file' => [
                'tmp_name' => $cancelledTemp,
                'size' => filesize($cancelledTemp) ?: 0,
                'error' => UPLOAD_ERR_OK,
                'name' => 'cancelled.txt',
                'type' => 'text/plain',
            ]]);

        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $hostRequest = \Infocyph\Runwire\RequestContext::create($runtime);
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);
        try {
            \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
                $hostRequest,
                null,
                static function () use ($handler, $cancelledRequest, $directory): void {
                    expect(fn() => $handler->processUploadRequest(
                        $cancelledRequest,
                        directory: $directory,
                        disk: 'uploads',
                    ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
                },
                $runtime,
            );
        } finally {
            $hostRequest->complete();
            \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        }
        expect($scanner->calls)->toBe(2)
            ->and($scanner->stagedContents)->toBe('cancel-during-scan')
            ->and(is_file($scanner->stagedPath ?? ''))->toBeFalse()
            ->and($disk->listContents($directory)->toArray())->toBe([]);
    } finally {
        if (is_string($cancelledTemp) && is_file($cancelledTemp)) {
            unlink($cancelledTemp);
        }
        if (is_file($temporary)) {
            unlink($temporary);
        }
        if ($disk->directoryExists($directory)) {
            $disk->deleteDirectory($directory);
        }
        foundationFilesystemRemoveDirectory($root);
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
        ['probe' => static function (array $definition) use ($filesystem): Filesystem {
            unset($definition);

            return $filesystem;
        }],
    );
    $container = new class implements ContainerInterface {
        #[\Override]
        public function get(string $id): never
        {
            throw new LogicException(sprintf('No test service "%s" is registered.', $id));
        }

        #[\Override]
        public function has(string $id): bool
        {
            unset($id);

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

    #[\Override]
    public function fileSize(string $path): int
    {
        ++$this->fileSizeReads;

        return parent::fileSize($path);
    }

    #[\Override]
    public function lastModified(string $path): int
    {
        ++$this->lastModifiedReads;

        return parent::lastModified($path);
    }

    #[\Override]
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


it('rejects cancelled Runwire uploads and downloads before Pathwise file side effects', function (): void {
    [$app, $root] = foundationFilesystemApp();
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $disk = $storage->disk('uploads');
    $responses = $app->make(FilesystemResponseFactory::class);
    $uploads = $app->make(FilesystemUploadRequestHandler::class);
    $directory = 'tests/runwire-' . bin2hex(random_bytes(6));
    $path = $directory . '/exists.txt';
    $disk->write($path, 'unchanged');
    $temp = tempnam(sys_get_temp_dir(), 'foundation-runwire-upload-');
    expect($temp)->toBeString();
    file_put_contents($temp, 'must-not-upload');
    $request = Request::fake(headers: ['Host' => 'localhost'], method: 'POST', uri: 'http://localhost/upload')
        ->withUploadedFiles(['file' => [
            'tmp_name' => $temp, 'size' => filesize($temp) ?: 0, 'error' => UPLOAD_ERR_OK,
            'name' => 'blocked.txt', 'type' => 'text/plain',
        ]]);

    $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
    $hostRequest = \Infocyph\Runwire\RequestContext::create($runtime);
    \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::bind($runtime);

    try {
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::share(
            $hostRequest,
            null,
            static function () use ($hostRequest, $responses, $uploads, $request, $path, $directory): void {
                $hostRequest->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
                expect(fn() => $responses->download(
                    Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download'),
                    $path,
                    directory: $directory,
                    disk: 'uploads',
                ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
                expect(fn() => $uploads->processUploadRequest(
                    $request,
                    directory: $directory,
                    disk: 'uploads',
                ))->toThrow(\Infocyph\Runwire\Exception\CancelledException::class);
            },
            $runtime,
        );
        expect($disk->read($path))->toBe('unchanged')
            ->and($disk->fileExists($directory . '/blocked.txt'))->toBeFalse();
    } finally {
        $hostRequest->complete();
        \Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::release($runtime);
        if (is_file($temp)) {
            unlink($temp);
        }
        $disk->deleteDirectory($directory);
        foundationFilesystemRemoveDirectory($root);
    }
});
