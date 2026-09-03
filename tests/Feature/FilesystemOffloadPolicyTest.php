<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\PathwiseFacade;
use Infocyph\Webrick\Request\Request;

beforeEach(function (): void {
    if (!class_exists(PathwiseFacade::class)) {
        $this->markTestSkipped('Install the filesystem module to run Pathwise integration tests.');
    }
});

/**
 * @param array<string, array{enabled:bool}> $offload
 * @return array{Application,string}
 */
function foundationFilesystemOffloadApp(array $offload = []): array
{
    $basePath = sys_get_temp_dir() . '/foundation-filesystem-offload-' . bin2hex(random_bytes(5));
    mkdir($basePath, 0775, true);

    return [Foundation::web([
        'base_path' => $basePath,
        '_config_cache' => false,
        'router' => ['cache' => false],
        'filesystem' => ['offload' => $offload],
    ]), $basePath];
}

it('keeps native file offload disabled unless the application opts in explicitly', function (): void {
    [$app, $basePath] = foundationFilesystemOffloadApp();
    $app->boot();
    $responses = $app->make(FilesystemResponseFactory::class);
    $request = Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download');

    try {
        expect(fn() => $responses->xSendfile($request, 'payload.txt'))
            ->toThrow(LogicException::class, 'X-Sendfile responses are disabled');
        expect(fn() => $responses->xAccelRedirect($request, '/internal/payload.txt', 'payload.txt'))
            ->toThrow(LogicException::class, 'X-Accel-Redirect responses are disabled');
    } finally {
        foundationFilesystemOffloadRemoveDirectory($basePath);
    }
});

it('preserves explicit X-Sendfile and X-Accel policy without Foundation body emission', function (): void {
    [$app, $basePath] = foundationFilesystemOffloadApp([
        'x_sendfile' => ['enabled' => true],
        'x_accel_redirect' => ['enabled' => true],
    ]);
    $app->boot();
    $storage = $app->make(StorageRegistry::class);
    $responses = $app->make(FilesystemResponseFactory::class);
    $transfers = $app->make(FilesystemTransferFactory::class);
    $disk = $storage->disk('uploads');
    $directory = 'tests/offload-' . uniqid('', true);
    $relativePath = $directory . '/payload.txt';
    $contents = 'Foundation native offload policy';
    $disk->write($relativePath, $contents);

    try {
        $request = Request::fake(headers: ['Host' => 'localhost'], uri: 'http://localhost/download');
        $localPath = $storage->localPath($relativePath, 'uploads');
        $sendfile = $responses->xSendfile(
            $request,
            $relativePath,
            directory: $directory,
            disk: 'uploads',
            headers: ['X-Foundation-Test' => 'sendfile'],
        );

        expect($sendfile->getStatusCode())->toBe(200)
            ->and($sendfile->getHeaderLine('X-Sendfile'))->toBe($localPath)
            ->and($sendfile->getHeaderLine('X-Foundation-Test'))->toBe('sendfile')
            ->and($sendfile->getBodySize())->toBe(0)
            ->and($sendfile->getFileBody())->toBeNull()
            ->and($sendfile->getProducer())->toBeNull();

        $accel = $responses->xAccelRedirect(
            $request,
            '/protected/foundation/payload.txt',
            $relativePath,
            directory: $directory,
            disk: 'uploads',
            inline: true,
        );
        expect($accel->getStatusCode())->toBe(200)
            ->and($accel->getHeaderLine('X-Accel-Redirect'))->toBe('/protected/foundation/payload.txt')
            ->and($accel->getHeaderLine('Content-Disposition'))->toContain('inline;')
            ->and($accel->getBodySize())->toBe(0)
            ->and($accel->getFileBody())->toBeNull()
            ->and($accel->getProducer())->toBeNull();

        expect(fn() => $responses->xSendfile($request, 's3://bucket/payload.txt'))
            ->toThrow(InvalidArgumentException::class, 'X-Sendfile requires a local filesystem path');
        expect(fn() => $responses->xAccelRedirect($request, '   ', $relativePath, disk: 'uploads'))
            ->toThrow(InvalidArgumentException::class, 'internal path must be non-empty');

        $manifest = $transfers->download($directory, 'uploads')
            ->prepareDownload($localPath);
        $conditional = $responses->xSendfile(
            Request::fake(
                headers: ['Host' => 'localhost', 'If-None-Match' => $manifest->etag],
                uri: 'http://localhost/download',
            ),
            $relativePath,
            directory: $directory,
            disk: 'uploads',
        );
        expect($conditional->getStatusCode())->toBe(304)
            ->and($conditional->getHeaderLine('ETag'))->toBe($manifest->etag)
            ->and($conditional->hasHeader('X-Sendfile'))->toBeFalse()
            ->and($conditional->getBodySize())->toBe(0);
    } finally {
        if ($disk->directoryExists($directory)) {
            $disk->deleteDirectory($directory);
        }
        foundationFilesystemOffloadRemoveDirectory($basePath);
    }
});

function foundationFilesystemOffloadRemoveDirectory(string $directory): void
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
