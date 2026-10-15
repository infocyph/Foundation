<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;

it('does not evaluate an untrusted executable release manifest in prevalidated boot', function (): void {
    $root = sys_get_temp_dir() . '/foundation-trust-' . bin2hex(random_bytes(8));
    $dir = $root . '/generations/probe';
    mkdir($dir, 0775, true);
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
        unlink($root . '/active.json');
        rmdir($dir);
        rmdir(dirname($dir));
        rmdir($root);
    }
});
