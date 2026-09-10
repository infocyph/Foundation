<?php

declare(strict_types=1);

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Filesystem\StorageRegistry;
use Infocyph\Pathwise\Storage\StorageContext;

beforeEach(function (): void {
    if (!class_exists(StorageContext::class)) {
        $this->markTestSkipped('Install Pathwise 4 to run filesystem isolation tests.');
    }
});

it('isolates identical logical disk names across Foundation applications in one process', function (): void {
    $baseA = sys_get_temp_dir() . '/foundation-pathwise4-a-' . bin2hex(random_bytes(5));
    $baseB = sys_get_temp_dir() . '/foundation-pathwise4-b-' . bin2hex(random_bytes(5));
    mkdir($baseA, 0775, true);
    mkdir($baseB, 0775, true);

    $configuration = static fn(string $root): ConfigRepository => new ConfigRepository([
        'filesystem' => [
            'default' => 'uploads',
            'disks' => [
                'uploads' => ['driver' => 'local', 'root' => $root],
            ],
        ],
    ]);

    $storageA = new StorageRegistry($configuration('storage/uploads'), new PathManager($baseA));
    $storageB = new StorageRegistry($configuration('storage/uploads'), new PathManager($baseB));

    try {
        expect($storageA->context())->not->toBe($storageB->context())
            ->and($storageA->path('same.txt', 'uploads'))->toBe('uploads://same.txt')
            ->and($storageB->path('same.txt', 'uploads'))->toBe('uploads://same.txt')
            ->and($storageA->localPath('same.txt', 'uploads'))->not->toBe($storageB->localPath('same.txt', 'uploads'));

        $storageA->disk('uploads')->write('same.txt', 'application-a');
        $storageB->disk('uploads')->write('same.txt', 'application-b');

        expect($storageA->disk('uploads')->read('same.txt'))->toBe('application-a')
            ->and($storageB->disk('uploads')->read('same.txt'))->toBe('application-b');
    } finally {
        foundationPathwise4IsolationRemove($baseA);
        foundationPathwise4IsolationRemove($baseB);
    }
});

it('keeps interleaved Fiber storage operations bound to the owning context', function (): void {
    $baseA = sys_get_temp_dir() . '/foundation-pathwise4-fiber-a-' . bin2hex(random_bytes(5));
    $baseB = sys_get_temp_dir() . '/foundation-pathwise4-fiber-b-' . bin2hex(random_bytes(5));
    mkdir($baseA, 0775, true);
    mkdir($baseB, 0775, true);

    $make = static fn(string $base): StorageRegistry => new StorageRegistry(
        new ConfigRepository([
            'filesystem' => [
                'default' => 'uploads',
                'disks' => ['uploads' => ['driver' => 'local', 'root' => 'storage/uploads']],
            ],
        ]),
        new PathManager($base),
    );
    $storageA = $make($baseA);
    $storageB = $make($baseB);

    try {
        $fiberA = new Fiber(function () use ($storageA): string {
            $storageA->disk('uploads')->write('fiber.txt', 'A');
            Fiber::suspend();

            return $storageA->disk('uploads')->read('fiber.txt');
        });
        $fiberB = new Fiber(function () use ($storageB): string {
            $storageB->disk('uploads')->write('fiber.txt', 'B');
            Fiber::suspend();

            return $storageB->disk('uploads')->read('fiber.txt');
        });

        $fiberA->start();
        $fiberB->start();
        $fiberB->resume();
        $fiberA->resume();

        expect($fiberA->getReturn())->toBe('A')
            ->and($fiberB->getReturn())->toBe('B');
    } finally {
        foundationPathwise4IsolationRemove($baseA);
        foundationPathwise4IsolationRemove($baseB);
    }
});

it('rejects an invalid default disk while constructing the application storage context', function (): void {
    expect(fn() => new StorageRegistry(
        new ConfigRepository([
            'filesystem' => [
                'default' => 'missing',
                'disks' => ['uploads' => ['driver' => 'local', 'root' => 'storage/uploads']],
            ],
        ]),
        new PathManager(sys_get_temp_dir()),
    ))->toThrow(InvalidArgumentException::class, "Default filesystem 'missing' is not configured");
});

function foundationPathwise4IsolationRemove(string $directory): void
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
