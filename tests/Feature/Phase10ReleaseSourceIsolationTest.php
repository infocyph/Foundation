<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseConfig;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

function foundationPhase10ReleaseHandler(): Response
{
    return Response::json(['source' => 'compiled']);
}

it('boots immutable releases without rediscovering application source files', function (): void {
    $project = foundationPhase10ReleaseProject();
    $releaseRoot = $project . '/storage/releases';
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => [
                    'pre' => [],
                    'post' => [],
                ],
            ],
        ],
    ];

    try {
        $release = new FoundationReleaseCompiler()->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: [
                'web' => [],
                'cli' => [],
                'worker' => [],
                'scheduler' => [],
            ],
            generation: 'phase10-source-isolation',
        );
        $generation = $releaseRoot . '/generations/phase10-source-isolation';
        $manifest = require $generation . '/foundation.php';

        expect(is_file($generation . '/config.php'))->toBeTrue()
            ->and($manifest['config_path'] ?? null)->toBe('config.php')
            ->and($manifest['config_sha256'] ?? null)->toMatch('/^[a-f0-9]{64}$/D');

        $releaseConfig = FoundationReleaseConfig::load(
            $generation . '/config.php',
            (string) $manifest['config_sha256'],
        );
        expect($releaseConfig->isCompiled())->toBeTrue()
            ->and($releaseConfig->get('app.base_path'))->toBe($project);

        file_put_contents(
            $project . '/config/app.php',
            "<?php\n\nthrow new RuntimeException('release rediscovered source config');\n",
        );
        file_put_contents(
            $project . '/bootstrap/providers.php',
            "<?php\n\nthrow new RuntimeException('release rediscovered source providers');\n",
        );
        file_put_contents(
            $project . '/routes/web.php',
            "<?php\n\nthrow new RuntimeException('release rediscovered source routes');\n",
        );

        $runtime = new FoundationReleaseRuntime();
        $callerConfig = ['app' => ['base_path' => '/caller-source-must-not-be-used']];
        $web = $runtime->webPrevalidated(
            $callerConfig,
            $releaseRoot,
            $release['manifest_sha256'],
        );
        $response = $web->kernel->handle(Request::fake(
            headers: ['Host' => 'phase10.test'],
            uri: 'https://phase10.test/phase10',
        ));
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('{"source":"compiled"}');

        $cli = $runtime->nonWeb($callerConfig, RuntimeMode::Cli, $releaseRoot);
        $trustedCli = $runtime->nonWebPrevalidated(
            $callerConfig,
            RuntimeMode::Cli,
            $releaseRoot,
            $release['manifest_sha256'],
        );
        expect($cli->application->runtimeMode())->toBe(RuntimeMode::Cli)
            ->and($trustedCli->application->runtimeMode())->toBe(RuntimeMode::Cli);

        file_put_contents($generation . '/config.php', "<?php\n\nreturn ['tampered' => true];\n");
        expect(fn() => $runtime->webPrevalidated(
            $callerConfig,
            $releaseRoot,
            $release['manifest_sha256'],
        ))->toThrow(RuntimeException::class, 'config trust identity mismatch');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationPhase10ReleaseRemove($project);
    }
});

it('builds from a read-only application image into a separate writable release root', function (): void {
    $project = foundationPhase10ReleaseProject();
    $releaseRoot = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase10-release-root-' . bin2hex(random_bytes(5));
    mkdir($releaseRoot, 0777, true);
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];

    try {
        chmod($project . '/bootstrap/providers.php', 0444);
        chmod($project . '/routes/web.php', 0444);
        chmod($project . '/bootstrap', 0555);
        chmod($project . '/config', 0555);
        chmod($project . '/routes', 0555);
        chmod($project, 0555);

        $release = new FoundationReleaseCompiler()->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: ['web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []],
            generation: 'readonly-source',
        );

        expect(is_file($release['manifest']))->toBeTrue()
            ->and($release['manifest'])->toStartWith($releaseRoot)
            ->and(is_file($project . '/bootstrap/cache/foundation.php'))->toBeFalse();
    } finally {
        chmod($project, 0777);
        foreach (['bootstrap', 'config', 'routes'] as $directory) {
            chmod($project . '/' . $directory, 0777);
        }
        chmod($project . '/bootstrap/providers.php', 0666);
        chmod($project . '/routes/web.php', 0666);
        foundationPhase10ReleaseRemove($project);
        foundationPhase10ReleaseRemove($releaseRoot);
    }
});

it('keeps the active generation when a later staged release fails to compile', function (): void {
    $project = foundationPhase10ReleaseProject();
    $releaseRoot = $project . '/storage/releases';
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $compiler = new FoundationReleaseCompiler();

    try {
        $stable = $compiler->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: ['web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []],
            generation: 'stable',
        );
        file_put_contents($project . '/routes/web.php', "<?php\n\nthis is not valid PHP;\n");

        expect(fn() => $compiler->buildAndActivate(
            $config,
            $releaseRoot,
            capabilities: ['web' => [], 'cli' => [], 'worker' => [], 'scheduler' => []],
            generation: 'broken',
        ))->toThrow(\ParseError::class);

        $status = $compiler->status($releaseRoot);
        expect($status['generation'])->toBe('stable')
            ->and($status['manifest_sha256'])->toBe($stable['manifest_sha256'])
            ->and(is_dir($releaseRoot . '/generations/broken'))->toBeFalse()
            ->and(glob($releaseRoot . '/generations/.staging-broken-*') ?: [])->toBe([]);
    } finally {
        foundationPhase10ReleaseRemove($project);
    }
});

function foundationPhase10ReleaseProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-phase10-source-' . bin2hex(random_bytes(5));
    mkdir($project . '/bootstrap', 0777, true);
    mkdir($project . '/config', 0777, true);
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/storage', 0777, true);

    file_put_contents(
        $project . '/bootstrap/providers.php',
        "<?php\n\ndeclare(strict_types=1);\n\nreturn [];\n",
    );
    file_put_contents(
        $project . '/routes/web.php',
        <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/phase10', 'foundationPhase10ReleaseHandler');
PHP,
    );

    return $project;
}

function foundationPhase10ReleaseRemove(string $directory): void
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
