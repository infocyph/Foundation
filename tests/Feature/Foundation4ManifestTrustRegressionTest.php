<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\FoundationReleaseManifest;
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

/** Test-only stream that changes PHP source on its second open. */
final class FoundationManifestChangingStream
{
    public static string $original = '';
    public static string $replacement = '';
    public static int $opens = 0;

    public mixed $context = null;

    private string $contents = '';
    private int $offset = 0;

    public function stream_eof(): bool
    {
        return $this->offset >= strlen($this->contents);
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        unset($path, $mode, $options, $openedPath);
        $this->contents = ++self::$opens === 1 ? self::$original : self::$replacement;
        $this->offset = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = substr($this->contents, $this->offset, $count);
        $this->offset += strlen($chunk);

        return $chunk;
    }

    public function stream_seek(int $offset, int $whence = SEEK_SET): bool
    {
        $position = match ($whence) {
            SEEK_CUR => $this->offset + $offset,
            SEEK_END => strlen($this->contents) + $offset,
            default => $offset,
        };
        if ($position < 0 || $position > strlen($this->contents)) {
            return false;
        }
        $this->offset = $position;

        return true;
    }

    /** @return array{mode:int,size:int} */
    public function stream_stat(): array
    {
        return $this->url_stat('', 0);
    }

    public function stream_tell(): int
    {
        return $this->offset;
    }

    /** @return array{mode:int,size:int} */
    public function url_stat(string $path, int $flags): array
    {
        unset($path, $flags);

        return ['mode' => 0100644, 'size' => strlen(self::$original)];
    }
}

it('evaluates one captured manifest source even when the original stream changes on reopen', function (): void {
    $manifest = [
        'format' => FoundationReleaseManifest::FORMAT,
        'generation' => 'probe',
        'environment' => 'testing',
        'config_fingerprint' => str_repeat('a', 32),
        'dependency_fingerprint' => str_repeat('b', 32),
        'config_path' => 'config.php',
        'config_sha256' => str_repeat('c', 64),
        'web' => [
            'release_manifest' => 'web/release.json',
            'runtime_manifest_sha256' => str_repeat('d', 64),
            'capabilities' => [],
        ],
    ];
    foreach (['cli', 'worker', 'scheduler'] as $mode) {
        $manifest[$mode] = [
            'intermix_path' => $mode . '/intermix.php',
            'digest' => str_repeat('e', 32),
            'metadata_path' => $mode . '/metadata.json',
            'metadata_sha256' => str_repeat('f', 64),
            'capabilities' => [],
        ];
    }
    FoundationReleaseManifest::assertValid($manifest);
    $marker = sys_get_temp_dir() . '/foundation-manifest-swap-' . bin2hex(random_bytes(8));
    FoundationManifestChangingStream::$original = '<?php return ' . var_export($manifest, true) . ';';
    FoundationManifestChangingStream::$replacement = '<?php file_put_contents('
        . var_export($marker, true) . ", 'replaced'); return " . var_export($manifest, true) . ';';
    FoundationManifestChangingStream::$opens = 0;

    $scheme = 'foundationmanifesttest';
    expect(stream_wrapper_register($scheme, FoundationManifestChangingStream::class))->toBeTrue();
    try {
        $result = FoundationReleaseManifest::load($scheme . '://release.php');
        expect($result['generation'])->toBe('probe')
            ->and(FoundationManifestChangingStream::$opens)->toBe(1)
            ->and(is_file($marker))->toBeFalse();
    } finally {
        stream_wrapper_unregister($scheme);
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});
