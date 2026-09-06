<?php

declare(strict_types=1);

it('keeps direct SHA-256 hashing inside protocol and cryptographic source boundaries', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $allowed = [
        '/Auth/Adapter/Epicrypt/',
        '/Auth/Adapter/Otp/',
        '/Auth/OAuth/',
        '/Auth/Support/',
        '/Security/',
    ];
    $violations = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $source = file_get_contents($file->getPathname());
        if (!is_string($source) || preg_match('/\bhash\s*\(\s*[\'\"]sha256[\'\"]/i', $source) !== 1) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root)));
        if (!array_any($allowed, static fn(string $prefix): bool => str_starts_with($relative, $prefix))) {
            $violations[] = 'src' . $relative;
        }
    }

    expect($violations)->toBe([]);
});
