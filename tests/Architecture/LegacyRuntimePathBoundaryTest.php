<?php

declare(strict_types=1);

it('contains no legacy InterMix resolver-map production path', function (): void {
    $source = dirname(__DIR__, 2) . '/src';
    $forbidden = [
        'ContainerCacheManager' => 'legacy Foundation container cache manager',
        '->compileTo(' => 'legacy InterMix compileTo resolver-map compilation',
        '->useCompiled(' => 'legacy InterMix useCompiled resolver-map activation',
        '->usePrevalidated(' => 'legacy InterMix usePrevalidated resolver-map activation',
        '->compilationReport(' => 'legacy InterMix resolver-map compilation report',
    ];
    $violations = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $contents = file_get_contents($file->getPathname());
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read source file "%s".', $file->getPathname()));
        }
        foreach ($forbidden as $needle => $reason) {
            if (str_contains($contents, $needle)) {
                $violations[] = sprintf(
                    '%s contains %s (%s)',
                    substr($file->getPathname(), strlen(dirname(__DIR__, 2)) + 1),
                    $needle,
                    $reason,
                );
            }
        }
    }

    expect($violations)->toBe([]);
});
