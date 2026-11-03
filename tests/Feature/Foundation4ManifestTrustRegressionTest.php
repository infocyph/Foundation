<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ReleaseGenerationLease;

it('does not evaluate an untrusted executable release manifest in prevalidated boot', function (): void {
    $root = sys_get_temp_dir() . '/foundation-trust-' . bin2hex(random_bytes(8));
    $dir = $root . '/generations/probe';
    mkdir($dir, 0775, true);
    ReleaseGenerationLease::initialize($dir);
    $marker = $root . '/executed.txt';
    $manifest = $dir . '/foundation.php';
    file_put_contents($root . '/active.json', json_encode([
        'format' => 1,
        'generation' => 'probe',
        'manifest' => 'generations/probe/foundation.php',
    ], JSON_THROW_ON_ERROR));
    file_put_contents($manifest, '<?php file_put_contents(' . var_export($marker, true) . ", 'executed'); return [];");

    try {
        $runtime = new FoundationReleaseRuntime();
        $wrong = str_repeat('a', 64);
        expect(fn() => $runtime->trustedActiveManifest($root, $wrong))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(fn() => $runtime->nonWebPrevalidated([], RuntimeMode::Cli, $root, $wrong))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(fn() => $runtime->webPrevalidated([], $root, $wrong))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($marker)) {
            unlink($marker);
        }
        unlink($manifest);
        unlink($dir . '/' . ReleaseGenerationLease::MARKER);
        unlink($root . '/active.json');
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir($root);
    }
});

it('refuses to execute mismatched trusted config bytes', function (): void {
    $file = tempnam(sys_get_temp_dir(), 'foundation-config-auth-');
    expect($file)->toBeString();
    $marker = $file . '.executed';

    try {
        file_put_contents(
            $file,
            '<?php file_put_contents(' . var_export($marker, true)
            . ", 'untrusted'); return ['format' => 1, 'config' => []];",
        );
        expect(fn() => \Infocyph\Foundation\Release\FoundationReleaseConfig::load(
            $file,
            str_repeat('0', 64),
        ))->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});

it('evaluates captured authenticated bytes from a cleaned private copy', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'foundation-trusted-copy-');
    expect($path)->toBeString();
    $marker = $path . '.executed';
    $trusted = "<?php return ['format' => 1, 'source_file' => __FILE__];";
    $sha256 = hash('sha256', $trusted);

    try {
        file_put_contents($path, $trusted);
        $loaded = \Infocyph\Foundation\Release\FoundationReleaseManifest::loadTrustedArray($path, $sha256);
        expect($loaded['source_file'])->not->toBe($path)
            ->and(is_file($loaded['source_file']))->toBeFalse();

        file_put_contents(
            $path,
            '<?php file_put_contents(' . var_export($marker, true) . ", 'tampered'); return ['format' => 1];",
        );
        expect(fn() => \Infocyph\Foundation\Release\FoundationReleaseManifest::loadTrustedArray($path, $sha256))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});
