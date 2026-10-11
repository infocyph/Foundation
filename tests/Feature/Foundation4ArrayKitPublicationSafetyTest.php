<?php

declare(strict_types=1);

use Infocyph\ArrayKit\Config\LazyFileConfig;
use Infocyph\Foundation\Config\ArrayKitConfigCache;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;

it('rejects a symlinked cache publication root before modifying its target', function (): void {
    $root = sys_get_temp_dir() . '/foundation4-cache-root-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $outside = $root . '/outside';
    mkdir($outside, 0700);
    $cache = $root . '/cache';
    $sentinel = $outside . '/sentinel';
    file_put_contents($sentinel, 'owned externally');
    symlink($outside, $cache);

    try {
        $writer = new ArrayKitConfigCache();
        expect(fn() => $writer->writeSingle(['app' => ['name' => 'test']], $cache))
            ->toThrow(RuntimeException::class, 'symbolic link');
        expect(fn() => $writer->writeSharded(['app' => ['name' => 'test']], $cache))
            ->toThrow(RuntimeException::class, 'symbolic link');
        expect(fn() => (new ConfigLoader())->writeCache(
            new ConfigRepository(['app' => ['name' => 'test']]),
            $cache,
            'single',
            $root,
        ))->toThrow(RuntimeException::class, 'symbolic link');

        expect($sentinel)->toBeFile()
            ->and(file_get_contents($sentinel))->toBe('owned externally')
            ->and($outside . '/config.php')->not->toBeFile();
    } finally {
        unlink($cache);
        unlink($sentinel);
        rmdir($outside);
        rmdir($root);
    }
});

it('does not traverse planted native generation symlinks while publishing a single cache', function (bool $nested): void {
    $root = sys_get_temp_dir() . '/foundation4-generation-link-' . bin2hex(random_bytes(6));
    mkdir($root, 0700, true);
    $cache = $root . '/cache';
    $outside = $root . '/outside';
    mkdir($cache, 0700);
    mkdir($outside, 0700);
    $sentinel = $outside . '/sentinel';
    file_put_contents($sentinel, 'must survive');

    $generation = $cache . '/.arraykit-gen-deadbeef';
    $link = $nested ? $generation . '/nested' : $generation;
    if ($nested) {
        mkdir($generation, 0700);
    }
    symlink($outside, $link);

    try {
        $published = false;
        try {
            (new ArrayKitConfigCache())->writeSingle(['app' => ['name' => 'safe']], $cache);
            $published = true;
        } catch (RuntimeException $exception) {
            // A safe refusal is valid if compromised generation paths are detected.
            expect(preg_match('/symlink|symbolic link/i', $exception->getMessage()))->toBe(1);
        }

        expect($sentinel)->toBeFile()
            ->and(file_get_contents($sentinel))->toBe('must survive');
        if ($published) {
            expect($cache . '/config.php')->toBeFile();
        }
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if ($nested) {
            rmdir($generation);
        }
        if (is_file($cache . '/config.php')) {
            unlink($cache . '/config.php');
        }
        unlink($sentinel);
        rmdir($outside);
        rmdir($cache);
        rmdir($root);
    }
})->with([false, true]);

it('preserves native generations for readers pinned before a single-mode transition', function (): void {
    $root = sys_get_temp_dir() . '/foundation4-pinned-generation-' . bin2hex(random_bytes(6));
    $cache = $root . '/cache';
    mkdir($cache, 0700, true);

    try {
        $writer = new ArrayKitConfigCache();
        $writer->writeSharded([
            'app' => ['name' => 'before'],
            'db' => ['host' => 'original'],
        ], $cache);

        $generation = trim((string) file_get_contents($cache . '/.arraykit-generation'));
        expect($generation)->toMatch('/^\.arraykit-gen-[a-f0-9]+$/');

        $reader = new LazyFileConfig(
            directory: $cache,
            namespaceCacheDirectory: $cache,
        );
        expect($reader->get('app.name'))->toBe('before');

        $writer->writeSingle(['app' => ['name' => 'after']], $cache);

        expect($cache . '/.arraykit-generation')->not->toBeFile()
            ->and($cache . '/' . $generation . '/db.php')->toBeFile()
            ->and($reader->get('db.host'))->toBe('original')
            ->and($writer->loadSingle($cache . '/config.php')['app']['name'] ?? null)->toBe('after');
    } finally {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($cache, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink()
                ? rmdir($entry->getPathname())
                : unlink($entry->getPathname());
        }
        rmdir($cache);
        rmdir($root);
    }
});
