<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Feature;

use Infocyph\Foundation\Application\RuntimeMode;

use Infocyph\Foundation\Release\FoundationReleaseManifest;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Foundation\Runtime\ReleaseGenerationLease;
use RuntimeException;

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
        if (!is_string($loaded['source_file'] ?? null)) {
            throw new \UnexpectedValueException('Trusted release metadata must contain its source path.');
        }
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

/**
 * Test-only PHP stream-wrapper callback; changes source on its second open.
 *
 * @api
 */
final class FoundationManifestChangingStream
{
    public static int $opens = 0;

    public static string $original = '';

    public static string $replacement = '';

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

it('does not reopen the original trusted Webrick manifest when its PHP source changes', function (): void {
    $project = sys_get_temp_dir() . '/foundation-web-capture-' . bin2hex(random_bytes(8));
    mkdir($project . '/routes', 0700, true);
    mkdir($project . '/bootstrap/cache', 0700, true);
    file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/captured', static fn(): string => 'captured');
PHP);

    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifestPath = $project . '/bootstrap/cache/release.json';
    $scheme = 'foundationwebcaptured';
    $marker = $project . '/replaced.txt';
    $registered = false;

    try {
        $release = new \Infocyph\Foundation\Routing\WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifestPath,
            capabilities: [],
        );
        $original = file_get_contents(
            \Infocyph\Webrick\Router\Build\ReleaseCompiler::runtimeManifestPath($manifestPath),
        );
        \PHPUnit\Framework\Assert::assertIsString($original);
        FoundationManifestChangingStream::$original = $original;
        FoundationManifestChangingStream::$replacement = '<?php file_put_contents('
            . var_export($marker, true) . ", 'unverified'); return [];";
        FoundationManifestChangingStream::$opens = 0;
        $registered = stream_wrapper_register($scheme, FoundationManifestChangingStream::class);
        expect($registered)->toBeTrue();

        $runtime = \Infocyph\Foundation\Routing\WebReleaseRuntime::loadPrevalidated(
            $config,
            $scheme . '://release.json',
            $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        );
        expect($runtime->kernel->handle(\Infocyph\Webrick\Request\Request::fake())->getStatusCode())->toBe(404);
        expect(FoundationManifestChangingStream::$opens)->toBe(1)
            ->and(is_file($marker))->toBeFalse();
    } finally {
        if ($registered) {
            stream_wrapper_unregister($scheme);
        }
        foundationResetWebrickProductionRegistries();
        new \Symfony\Component\Filesystem\Filesystem()->remove($project);
    }
});

it('validates and decodes the same captured bytes for trusted generated runtime JSON', function (): void {
    $payload = ['schema_version' => 2, 'runtime' => 'worker', 'providers' => ['TrustedProvider']];
    $original = json_encode($payload, JSON_THROW_ON_ERROR);
    FoundationManifestChangingStream::$original = $original;
    FoundationManifestChangingStream::$replacement = json_encode(
        ['schema_version' => 2, 'runtime' => 'worker', 'providers' => ['ReplacedProvider']],
        JSON_THROW_ON_ERROR,
    );
    FoundationManifestChangingStream::$opens = 0;
    $scheme = 'foundationmetadatatrust';
    expect(stream_wrapper_register($scheme, FoundationManifestChangingStream::class))->toBeTrue();

    try {
        $path = $scheme . '://worker-runtime';
        $metadata = \Infocyph\Foundation\Runtime\GeneratedRuntimeMetadata::read(
            $path,
            hash('sha256', $original),
        );
        expect($metadata)->toBe($payload)
            ->and(FoundationManifestChangingStream::$opens)->toBe(1);

        FoundationManifestChangingStream::$opens = 0;
        expect(fn() => \Infocyph\Foundation\Runtime\GeneratedRuntimeMetadata::read(
            $path,
            str_repeat('0', 64),
        ))->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(FoundationManifestChangingStream::$opens)->toBe(1);
    } finally {
        stream_wrapper_unregister($scheme);
    }
});

/** Test-only provider for authenticated worker topology artifacts. */
final class FoundationManifestTrustedWorkerProvider implements \Infocyph\Foundation\Worker\WorkerProvider
{
    #[\Override]
    public function run(\Infocyph\Foundation\Worker\WorkerRuntime $runtime): int
    {
        unset($runtime);

        return 0;
    }
}

it('loads the captured authenticated config bytes without reopening the original source', function (): void {
    $marker = sys_get_temp_dir() . '/foundation-config-swap-' . bin2hex(random_bytes(8));
    $original = "<?php return ['format' => 1, 'config' => ['app' => ['env' => 'trusted']]];";
    FoundationManifestChangingStream::$original = $original;
    FoundationManifestChangingStream::$replacement = '<?php file_put_contents('
        . var_export($marker, true) . ", 'replacement'); return ['format' => 1, 'config' => ['app' => ['env' => 'untrusted']]];";
    FoundationManifestChangingStream::$opens = 0;
    $scheme = 'foundationconfigcapture';

    expect(stream_wrapper_register($scheme, FoundationManifestChangingStream::class))->toBeTrue();

    try {
        $config = \Infocyph\Foundation\Release\FoundationReleaseConfig::load(
            $scheme . '://config.php',
            hash('sha256', $original),
        );
        expect($config->get('app.env'))->toBe('trusted')
            ->and(FoundationManifestChangingStream::$opens)->toBe(1)
            ->and(is_file($marker))->toBeFalse();
    } finally {
        stream_wrapper_unregister($scheme);
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});

it('loads the captured authenticated worker topology without reopening the source', function (): void {
    $marker = sys_get_temp_dir() . '/foundation-worker-swap-' . bin2hex(random_bytes(8));
    $payload = [
        'format' => 1,
        'providers' => ['trusted' => FoundationManifestTrustedWorkerProvider::class],
    ];
    $original = '<?php return ' . var_export($payload, true) . ';';
    FoundationManifestChangingStream::$original = $original;
    FoundationManifestChangingStream::$replacement = '<?php file_put_contents('
        . var_export($marker, true) . ", 'replacement'); return ['format' => 1, 'providers' => [];";
    FoundationManifestChangingStream::$opens = 0;
    $scheme = 'foundationworkercapture';

    expect(stream_wrapper_register($scheme, FoundationManifestChangingStream::class))->toBeTrue();

    try {
        $providers = new \Infocyph\Foundation\Worker\WorkerTopology()->load(
            $scheme . '://providers.php',
            hash('sha256', $original),
        );
        expect($providers['trusted']['provider'])->toBe(FoundationManifestTrustedWorkerProvider::class)
            ->and(FoundationManifestChangingStream::$opens)->toBe(1)
            ->and(is_file($marker))->toBeFalse();
    } finally {
        stream_wrapper_unregister($scheme);
        if (is_file($marker)) {
            unlink($marker);
        }
    }
});

it('rejects an unauthenticated generation manifest before loading its worker topology', function (): void {
    $root = sys_get_temp_dir() . '/foundation-worker-generation-' . bin2hex(random_bytes(8));
    $directory = $root . '/generations/probe';
    mkdir($directory, 0700, true);
    $marker = $root . '/executed.txt';
    $path = $directory . '/foundation.php';
    file_put_contents($path, '<?php file_put_contents('
        . var_export($marker, true) . ", 'untrusted'); return [];");

    try {
        $generation = new \Infocyph\Foundation\Runtime\LoadedReleaseGeneration(
            $root,
            'probe',
            str_repeat('0', 64),
        );
        expect(fn() => new \Infocyph\Foundation\Worker\WorkerTopology()->loadGeneration($generation))
            ->toThrow(RuntimeException::class, 'trust identity mismatch');
        expect(is_file($marker))->toBeFalse();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
        if (is_file($marker)) {
            unlink($marker);
        }
        rmdir($directory);
        rmdir(dirname($directory));
        rmdir($root);
    }
});
