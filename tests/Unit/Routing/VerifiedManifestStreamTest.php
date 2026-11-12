<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Tests\Unit\Routing;

use Infocyph\Foundation\Routing\VerifiedManifestStream;
use Infocyph\Webrick\Router\Build\ReleaseCompiler;
use UnexpectedValueException;

it('loads verified PHP and JSON release manifest snapshots through the native Webrick validator', function (): void {
    $manifest = [
        'format' => ReleaseCompiler::RELEASE_FORMAT,
        'environment' => 'production',
        'config_fingerprint' => str_repeat('a', 32),
        'intermix' => ['path' => '/immutable/container.php', 'digest' => str_repeat('b', 32)],
        'webrick' => [
            'path' => '/immutable/router.php',
            'digest' => str_repeat('c', 32),
            'fingerprint' => str_repeat('d', 32),
        ],
    ];
    $php = '<?php return ' . var_export($manifest, true) . ';';
    $json = json_encode($manifest, JSON_THROW_ON_ERROR);

    expect(VerifiedManifestStream::load($php, true))->toBe($manifest)
        ->and(VerifiedManifestStream::load($json, false))->toBe($manifest);

    // Subsequent requests must not retain a previous verified manifest snapshot.
    expect(VerifiedManifestStream::load($php, true))->toBe($manifest);
});

it('rejects an invalid verified release schema without bypassing Webrick validation', function (bool $runtimePhp): void {
    $malformed = ['format' => 'unsupported', 'environment' => 'production'];
    $contents = $runtimePhp
        ? '<?php return ' . var_export($malformed, true) . ';'
        : json_encode($malformed, JSON_THROW_ON_ERROR);

    expect(fn(): array => VerifiedManifestStream::load($contents, $runtimePhp))
        ->toThrow(UnexpectedValueException::class, 'Malformed Webrick release manifest');
})->with([true, false]);
