<?php

declare(strict_types=1);

use Infocyph\Foundation\Release\ActiveGeneration;
use Infocyph\Foundation\Release\FoundationReleaseBuildLock;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseManifest;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ReleaseGenerationLease;

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

        $active->activate($root, 'gen-one');
        expect($active->current($root)['generation'])->toBe('gen-one')
            ->and($active->replacementRequired($root, 'gen-two'))->toBeTrue();
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

it('serializes release build-plane mutations without changing the active generation', function (): void {
    $root = foundationReleaseInfrastructureRoot();
    $active = new ActiveGeneration();

    try {
        foundationReleaseInfrastructureGeneration($root, 'stable');
        $active->activate($root, 'stable');

        $lock = FoundationReleaseBuildLock::acquire($root);
        try {
            expect(fn() => FoundationReleaseBuildLock::acquire($root))
                ->toThrow(RuntimeException::class, 'already in progress')
                ->and(fn() => new FoundationReleaseCompiler()->prune($root))
                ->toThrow(RuntimeException::class, 'already in progress')
                ->and($active->current($root)['generation'])->toBe('stable');
        } finally {
            $lock->release();
        }

        $reacquired = FoundationReleaseBuildLock::acquire($root);
        $reacquired->release();
        expect($active->current($root)['generation'])->toBe('stable');
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

        $runtime = new FoundationReleaseRuntime();
        $trusted = $runtime->trustedActiveManifest($root, $sha);
        expect($trusted[0])->toBe('trusted')
            ->and($trusted[1]['generation'])->toBe('trusted');

        expect(fn() => $runtime->trustedActiveManifest($root, str_repeat('0', 64)))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

it('rejects a trusted release when its dependency identity no longer matches', function (): void {
    $root = foundationReleaseInfrastructureRoot();

    try {
        $manifestPath = foundationReleaseInfrastructureGeneration($root, 'dependency-mismatch');
        $manifest = require $manifestPath;
        $manifest['dependency_fingerprint'] = str_repeat('0', 32);
        FoundationReleaseManifest::write($manifestPath, $manifest);
        new ActiveGeneration()->activate($root, 'dependency-mismatch');
        $sha = hash_file('sha256', $manifestPath);
        expect($sha)->toBeString();

        expect(fn() => new FoundationReleaseRuntime()->trustedActiveManifest($root, (string) $sha))
            ->toThrow(RuntimeException::class, 'dependency identity does not match');
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

it('preserves draining generations until their runtime lease is released', function (): void {
    $root = foundationReleaseInfrastructureRoot();
    $compiler = new FoundationReleaseCompiler();

    try {
        foundationReleaseInfrastructureGeneration($root, 'draining');
        foundationReleaseInfrastructureGeneration($root, 'active');
        touch($root . '/generations/draining', 100);
        touch($root . '/generations/active', 200);
        new ActiveGeneration()->activate($root, 'active');

        $lease = ReleaseGenerationLease::acquireShared($root, 'draining');
        try {
            expect($compiler->prune($root, keep: 1))->toBe([])
                ->and(is_dir($root . '/generations/draining'))->toBeTrue();
        } finally {
            $lease->release();
        }

        expect($compiler->prune($root, keep: 1))->toBe(['draining'])
            ->and(is_dir($root . '/generations/draining'))->toBeFalse();
    } finally {
        foundationReleaseInfrastructureRemove($root);
    }
});

it('reports and clears active release generations through build-plane APIs', function (): void {
    $root = foundationReleaseInfrastructureRoot();
    $compiler = new FoundationReleaseCompiler();
    $active = new ActiveGeneration();

    try {
        $manifestPath = foundationReleaseInfrastructureGeneration($root, 'reportable');
        $active->activate($root, 'reportable');
        $expectedSha256 = hash_file('sha256', $manifestPath);
        if (!is_string($expectedSha256)) {
            throw new RuntimeException('Unable to hash release fixture.');
        }

        $status = $compiler->status($root);
        expect($active->exists($root))->toBeTrue()
            ->and($status['ready'])->toBeTrue()
            ->and($status['generation'])->toBe('reportable')
            ->and($status['manifest'])->toBe($manifestPath)
            ->and($status['manifest_sha256'])->toBe($expectedSha256);

        expect($compiler->clear($root))->toBeTrue()
            ->and($active->exists($root))->toBeFalse()
            ->and(is_dir($root . '/generations'))->toBeFalse()
            ->and($compiler->clear($root))->toBeFalse();

        $cleared = $compiler->status($root);
        expect($cleared['ready'])->toBeFalse()
            ->and($cleared['generation'])->toBeNull()
            ->and($cleared['manifest'])->toBeNull()
            ->and($cleared['manifest_sha256'])->toBeNull();
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
        'config_fingerprint' => str_repeat('c', 32),
        'dependency_fingerprint' => FoundationReleaseManifest::dependencyFingerprint(),
        'config_path' => 'config.php',
        'config_sha256' => str_repeat('e', 64),
        'web' => [
            'release_manifest' => 'web/release.json',
            'runtime_manifest_sha256' => str_repeat('d', 64),
            'capabilities' => [],
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
    ReleaseGenerationLease::initialize($directory);

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
