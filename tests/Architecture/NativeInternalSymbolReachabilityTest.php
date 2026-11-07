<?php

declare(strict_types=1);

it('does not retain orphaned internal native-integration owners', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $sources = [];
    $scan = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($scan as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source)) {
            throw new RuntimeException('Unable to inspect production source.');
        }

        $sources[$file->getPathname()] = $source;
    }

    $combined = implode("\n", $sources);
    $violations = [];
    foreach ($sources as $path => $source) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($root) + 1));
        if (!str_contains($relative, '/Internal/') && !str_ends_with($relative, 'GraphFactory.php')) {
            continue;
        }

        $class = pathinfo($relative, PATHINFO_FILENAME);
        if (substr_count($combined, $class) > substr_count($source, $class)) {
            continue;
        }

        $violations[] = $relative;
    }

    expect($violations)->toBe([]);
});
