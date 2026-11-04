<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Symfony\Component\Filesystem\Filesystem;

it('refuses to publish a compiled route after an authentication preset typo', function (): void {
    $root = sys_get_temp_dir() . '/foundation-preset-compile-' . bin2hex(random_bytes(8));
    $routes = $root . '/routes';
    mkdir($routes, 0700, true);
    file_put_contents($routes . '/web.php', <<<'PHP'
<?php

$presets->group($router, 'web-aut', static function ($routes): void {
    $routes->get('/private', static fn(): string => 'unprotected');
});
PHP);

    $routerArtifact = $root . '/build/router.php';
    try {
        expect(fn() => new WebReleaseCompiler()->compile(
            [
                'app' => ['base_path' => $root, 'env' => 'production'],
                'router' => ['files' => ['web.php']],
            ],
            $root . '/build/intermix.php',
            $routerArtifact,
            $root . '/build/release.json',
            capabilities: [],
        ))->toThrow(InvalidArgumentException::class, 'Unknown route middleware preset');

        expect(is_file($routerArtifact))->toBeFalse();
    } finally {
        new Filesystem()->remove($root);
    }
});
