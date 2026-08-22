<?php

declare(strict_types=1);

it('does not restore a Console package or namespace boundary', function (): void {
    $root = dirname(__DIR__, 2);

    expect(is_dir($root . '/src/Console'))->toBeFalse();

    $forbidden = [
        'Infocyph\\Console\\',
        'Infocyph\\Foundation\\Console\\',
    ];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS),
    );

    $violations = [];
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
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

    expect($violations)->toBe([]);
});
