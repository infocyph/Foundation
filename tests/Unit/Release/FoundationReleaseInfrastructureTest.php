<?php

declare(strict_types=1);

use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseManifest;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;

it('publishes and switches only complete immutable Foundation generations', function (): void {
    $root = foundationReleaseInfrastructureRoot();
    $active = new ActiveGeneration();

    try {
        foundationReleaseInfrastructureGeneration($root, 'gen-one');
        $active->activate($root, 'gen-one');
        expect($active->current($root)['generation'])->toBe('gen-one')
            ->and($active->replacementRequired($root, 'gen-one'))->toBeFalse();

        expect(fn() => $active->activate($root, 'missing-generation'))
            ->toThrow(RuntimeException::class);
        expect($active->current($root)['generation'])->toBe('gen-one');

        foundationReleaseInfrastructureGeneration($root, 'gen-two');
        $active->activate($root, 'gen-two');
        expect($active->current($root)['generation'])->toBe('gen-two')
            ->and($active->replacementRequired($root, 'gen-one'))->toBeTrue();
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

it('requires external trust for prevalidated Foundation generation loading', function (): void {
    $root = foundationReleaseInfrastructureRoot();

    try {
        $manifestPath = foundationReleaseInfrastructureGeneration($root, 'trusted');
        new ActiveGeneration()->activate($root, 'trusted');
        $sha = hash_file('sha256', $manifestPath);
        if (!is_string($sha)) {
            throw new RuntimeException('Unable to hash release fixture.');
        }

        $trusted = new FoundationReleaseRuntime()->trustedActiveManifest($root, $sha);
        expect($trusted[0])->toBe('trusted')
            ->and($trusted[1]['generation'])->toBe('trusted');

        expect(fn() => new FoundationReleaseRuntime()->trustedActiveManifest($root, str_repeat('0', 64)))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

it('rejects traversal paths in the Foundation generation manifest', function (): void {
    $manifest = foundationReleaseInfrastructureManifest('bad');
    $manifest['worker']['intermix_path'] = '../worker.php';

    expect(fn() => FoundationReleaseManifest::assertValid($manifest))
        ->toThrow(UnexpectedValueException::class, 'generation-relative');
});

it('prunes old generations explicitly while preserving active and newest releases', function (): void {
    $root = foundationReleaseInfrastructureRoot();

    try {
        foreach (['oldest', 'middle', 'active'] as $index => $generation) {
            foundationReleaseInfrastructureGeneration($root, $generation);
            touch($root . '/generations/' . $generation, 100 + $index);
        }
        new ActiveGeneration()->activate($root, 'active');
        $removed = new FoundationReleaseCompiler()->prune($root, keep: 2);

        expect($removed)->toBe(['oldest'])
            ->and(is_dir($root . '/generations/middle'))->toBeTrue()
            ->and(is_dir($root . '/generations/active'))->toBeTrue();
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

/** @return array<string,mixed> */
function foundationReleaseInfrastructureManifest(string $generation): array
{
    $runtime = static fn(string $name): array => [
        'intermix_path' => $name . '/container.php',
        'digest' => str_repeat('a', 32),
        'metadata_path' => $name . '/container.php.foundation.json',
        'metadata_sha256' => str_repeat('b', 64),
        'capabilities' => [],
    ];

    return [
        'format' => FoundationReleaseManifest::FORMAT,
        'generation' => $generation,
        'environment' => 'production',
        'config_fingerprint' => str_repeat('c', 64),
        'web' => [
            'release_manifest' => 'web/release.json',
            'runtime_manifest_sha256' => str_repeat('d', 64),
        ],
        'cli' => $runtime('cli'),
        'worker' => $runtime('worker'),
        'scheduler' => $runtime('scheduler'),
    ];
}

function foundationReleaseInfrastructureGeneration(string $root, string $generation): string
{
    $directory = $root . '/generations/' . $generation;
    mkdir($directory, 0777, true);

    return FoundationReleaseManifest::write(
        $directory . '/foundation.php',
        foundationReleaseInfrastructureManifest($generation),
    );
}

function foundationReleaseInfrastructureRoot(): string
{
    $root = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-release-infrastructure-' . bin2hex(random_bytes(5));
    mkdir($root . '/generations', 0777, true);

    return $root;
}

function foundationReleaseInfrastructureRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}
