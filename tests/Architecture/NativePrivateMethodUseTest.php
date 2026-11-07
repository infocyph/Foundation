<?php

declare(strict_types=1);

it('rejects unreferenced production-private methods after native ownership cleanup', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $violations = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException('Unable to inspect production source.');
        }

        // A trait's private methods may be called from its consuming class.
        if (preg_match('/\\btrait\\s+[A-Za-z_]/', $source) === 1) {
            continue;
        }

        preg_match_all(
            '/\\bprivate\\s+(?:(?:static|final|readonly)\\s+)*function\\s+&?([A-Za-z_][A-Za-z_0-9]*)\\s*\\(/',
            $source,
            $matches,
        );

        foreach ($matches[1] as $method) {
            if (str_starts_with($method, '__') || substr_count($source, $method) > 1) {
                continue;
            }

            $violations[] = substr($file->getPathname(), strlen($root) + 1) . '::' . $method;
        }
    }

    expect($violations)->toBe([]);
});
