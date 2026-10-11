<?php

declare(strict_types=1);

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
        (new ArrayKitConfigCache())->writeSingle(['app' => ['name' => 'safe']], $cache);

        expect($sentinel)->toBeFile()
            ->and(file_get_contents($sentinel))->toBe('must survive')
            ->and($cache . '/config.php')->toBeFile();
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
