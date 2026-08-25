<?php

declare(strict_types=1);

it('does not restore a Console package or namespace boundary', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir($root . '/src/Console'))->toBeFalse();

    $forbidden = [
        'Infocyph\\Console\\',
        'Infocyph\\Foundation\\Console\\',
    ];
    $roots = [
        $root . '/src',
        $root . '/resources/stubs',
    ];

    $violations = [];
    foreach ($roots as $scanRoot) {
        if (!is_dir($scanRoot)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile()) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            foreach ($forbidden as $needle) {
                if (str_contains($contents, $needle)) {
                    $violations[] = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname())
                        . ' contains ' . $needle;
                }
            }
        }
    }

    expect($violations)->toBe([]);
});
