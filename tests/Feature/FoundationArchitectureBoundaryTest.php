<?php

declare(strict_types=1);

it('enforces Foundation production ownership boundaries', function (): void {
    $root = dirname(__DIR__, 2) . '/src';
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );
    $forbiddenEverywhere = [
        'Infocyph\\Infbyte\\',
        'Infocyph\\InfByte\\',
        'Infocyph\\PHPForge\\',
        'Infocyph\\PHPProbe\\',
    ];
    $executionRoots = [
        $root . '/Auth/',
        $root . '/Http/',
        $root . '/Messaging/',
        $root . '/Scheduling/',
        $root . '/Session/',
        $root . '/Worker/',
    ];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace('\\', '/', $file->getPathname());
        $source = file_get_contents($file->getPathname());
        expect($source)->toBeString();

        foreach ($forbiddenEverywhere as $namespace) {
            expect($source)->not->toContain($namespace);
        }

        if (!str_contains($path, '/src/Testing/')) {
            expect($source)->not->toContain('Infocyph\\Foundation\\Testing\\');
        }

        if (array_any($executionRoots, static fn(string $prefix): bool => str_starts_with($path, str_replace('\\', '/', $prefix)))) {
            expect($source)
                ->not->toContain('FoundationReleaseCompiler')
                ->not->toContain('WebReleaseCompiler')
                ->not->toContain('GeneratedRuntimeCompiler');
        }
    }
});

it('keeps optional specialist packages out of the Foundation runtime requirement set', function (): void {
    $composer = json_decode(
        file_get_contents(dirname(__DIR__, 2) . '/composer.json') ?: '',
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $required = array_keys(is_array($composer['require'] ?? null) ? $composer['require'] : []);

    expect($required)->toContain(
        'infocyph/arraykit',
        'infocyph/cachelayer',
        'infocyph/intermix',
        'infocyph/uid',
        'infocyph/webrick',
    )->not->toContain(
        'infocyph/dblayer',
        'infocyph/epicrypt',
        'infocyph/omnibus',
        'infocyph/otp',
        'infocyph/pathwise',
        'infocyph/reqshield',
        'infocyph/runwire',
        'infocyph/talkingbytes',
        'web-auth/webauthn-lib',
    );
});
