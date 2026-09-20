<?php

declare(strict_types=1);

use Infocyph\Foundation\Filesystem\FilesystemResponseFactory;
use Infocyph\Foundation\Filesystem\FilesystemTransferFactory;
use Infocyph\Foundation\Filesystem\FilesystemUploadRequestHandler;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Foundation\Foundation;
use Infocyph\Pathwise\Exceptions\DownloadException;
use Infocyph\Pathwise\StreamHandler\UploadTrustProfile;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Body\FileBody;

it('applies the Pathwise 4.1 untrusted upload profile to Webrick-facing transfers', function (): void {
    $basePath = foundationPathwise41Base('upload');

    try {
        $app = Foundation::web([
            'base_path' => $basePath,
            '_config_cache' => false,
            'router' => ['cache' => false],
        ]);
        $app->boot();

        $processor = $app->make(FilesystemTransferFactory::class)->upload('tests/strict', 'uploads');
        $info = $processor->getInfo();

        expect($processor->getTrustProfile())->toBe(UploadTrustProfile::UNTRUSTED_DATA)
            ->and($info['maxChunkCount'])->toBeGreaterThan(0)
            ->and($info['maxChunkSize'])->toBeGreaterThan(0)
            ->and($info['namingStrategy'])->toBe('hash')
            ->and($info['strictContentTypeValidation'])->toBeTrue();

        $source = tempnam(sys_get_temp_dir(), 'foundation-pathwise41-');
        if ($source === false) {
            throw new RuntimeException('Unable to allocate the upload source.');
        }
        file_put_contents($source, 'strict upload body');

        $request = Request::fake(
            headers: ['Host' => 'localhost'],
            method: 'POST',
            uri: 'http://localhost/upload',
        )->withUploadedFiles(['file' => [
            'tmp_name' => $source,
            'size' => filesize($source) ?: 0,
            'error' => UPLOAD_ERR_OK,
            'name' => '../../client-owned.txt',
            'type' => 'text/plain',
        ]]);

        $stored = $app->make(FilesystemUploadRequestHandler::class)
            ->processUploadRequest($request, directory: 'tests/strict', disk: 'uploads');
        [$disk, $location] = $app->make(StorageRegistry::class)
            ->context()
            ->resolve($stored, 'uploads');

        expect($stored)->toStartWith('uploads://tests/strict/upload_')
            ->and($stored)->not->toContain('client-owned')
            ->and($stored)->not->toContain('..')
            ->and($disk->read($location))->toBe('strict upload body');

        if (is_file($source)) {
            unlink($source);
        }
    } finally {
        foundationPathwise41Remove($basePath);
    }
});

it('resolves public files through Pathwise before Webrick owns the response', function (): void {
    $basePath = foundationPathwise41Base('public');
    mkdir($basePath . '/public/assets', 0775, true);
    file_put_contents($basePath . '/public/assets/app.css', 'body{display:block}');
    file_put_contents($basePath . '/private.txt', 'private');

    try {
        $app = Foundation::web([
            'base_path' => $basePath,
            '_config_cache' => false,
            'router' => ['cache' => false],
        ]);
        $app->boot();

        $responses = $app->make(FilesystemResponseFactory::class);
        $request = Request::fake(
            headers: ['Host' => 'localhost'],
            uri: 'http://localhost/assets/app.css',
        );
        $response = $responses->publicFile($request, 'assets/app.css');

        expect($response->getStatusCode())->toBe(200)
            ->and($response->getHeaderLine('Content-Disposition'))->toContain('inline;')
            ->and($response->getFileBody())->toBeInstanceOf(FileBody::class)
            ->and($response->getProducer())->toBeNull()
            ->and($response->getFileBody()?->read(strlen('body{display:block}')))->toBe('body{display:block}');

        expect(fn() => $responses->publicFile($request, '../private.txt'))
            ->toThrow(DownloadException::class, 'traversal');

        $link = $basePath . '/public/assets/private-link.txt';
        if (@symlink($basePath . '/private.txt', $link)) {
            expect(fn() => $responses->publicFile($request, 'assets/private-link.txt'))
                ->toThrow(DownloadException::class, 'symbolic link');
            unlink($link);
        }
    } finally {
        foundationPathwise41Remove($basePath);
    }
});

function foundationPathwise41Base(string $suffix): string
{
    $base = sys_get_temp_dir() . '/foundation-pathwise41-' . $suffix . '-' . bin2hex(random_bytes(6));
    mkdir($base, 0775, true);

    return $base;
}

function foundationPathwise41Remove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        if ($file->isLink()) {
            unlink($file->getPathname());

            continue;
        }
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
