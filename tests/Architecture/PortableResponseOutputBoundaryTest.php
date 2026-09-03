<?php

declare(strict_types=1);

it('keeps Foundation portable response producers free of direct output streams', function (): void {
    $source = realpath(__DIR__ . '/../../src');
    if ($source === false) {
        throw new RuntimeException('Unable to locate the Foundation source directory.');
    }

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
        if (str_contains($contents, 'php://output')) {
            $violations[] = str_replace($source . DIRECTORY_SEPARATOR, '', $file->getPathname());
        }
    }

    sort($violations);

    expect($violations)->toBe([]);
});
