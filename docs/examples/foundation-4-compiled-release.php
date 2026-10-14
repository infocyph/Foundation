<?php

declare(strict_types=1);

// Run from a Composer-installed, all-specialist production --no-dev consumer.
// The caller deliberately owns this smoke's transient build directory.
require getcwd() . '/vendor/autoload.php';

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Release\FoundationReleaseCompiler;
use Infocyph\Foundation\Release\FoundationReleaseRuntime;
use Infocyph\Runwire\RequestContext;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class FoundationNoDevReleaseSmokeHandler
{
    public function __invoke(): Response
    {
        return Response::json(['compiled' => true]);
    }
}

$project = sys_get_temp_dir() . '/foundation-no-dev-release-' . bin2hex(random_bytes(6));

foreach (['bootstrap', 'routes', 'storage'] as $directory) {
    if (!mkdir($project . '/' . $directory, 0700, true)) {
        throw new RuntimeException('Unable to create release smoke fixture.');
    }
}

file_put_contents($project . '/bootstrap/providers.php', "<?php\n\nreturn ['common' => []];\n");
file_put_contents($project . '/routes/workers.php', "<?php\n\nreturn [];\n");
file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/no-dev', FoundationNoDevReleaseSmokeHandler::class);
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
$root = $project . '/storage/releases';

try {
    $build = new FoundationReleaseCompiler()->buildAndActivate(
        $config,
        $root,
        capabilities: ['web' => [], 'cli' => [], 'worker' => ['runwire'], 'scheduler' => []],
        generation: 'no-dev-consumer',
    );
    $sha = $build['manifest_sha256'];
    $loader = new FoundationReleaseRuntime();
    $web = $loader->webPrevalidated($config, $root, $sha);
    $response = $web->kernel->handle(Request::fake(
        headers: ['Host' => 'release.test'],
        uri: 'https://release.test/no-dev',
    ));
    if ($response->getStatusCode() !== 200
        || json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR)
            !== ['compiled' => true]
    ) {
        throw new RuntimeException('Trusted compiled web runtime failed the consumer smoke.');
    }

    foreach ([RuntimeMode::Cli, RuntimeMode::Scheduler] as $mode) {
        $generated = $loader->nonWebPrevalidated($config, $mode, $root, $sha);
        if ($generated->application->runtimeMode() !== $mode) {
            throw new RuntimeException('Compiled non-web runtime mode mismatch.');
        }
    }

    $worker = $loader->nonWebPrevalidated($config, RuntimeMode::Worker, $root, $sha);
    $host = RuntimeContext::standalone();
    $request = RequestContext::create($host);

    try {
        $worker->application->execution()->run(
            static function () use ($worker, $host, $request): void {
                if ($worker->application->make(RuntimeContext::class) !== $host
                    || $worker->application->make(RequestContext::class) !== $request
                ) {
                    throw new RuntimeException('Compiled worker changed host-owned Runwire identity.');
                }
            },
            [RuntimeContext::class => $host, RequestContext::class => $request],
        );
    } finally {
        $request->complete();
    }
    fwrite(STDOUT, "Trusted Foundation 4 no-dev web/CLI/worker/scheduler generation passed.\n");
} finally {
    if (is_dir($project)) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($project, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $file->isLink() || !$file->isDir()
                ? unlink($file->getPathname())
                : rmdir($file->getPathname());
        }
        rmdir($project);
    }
}
