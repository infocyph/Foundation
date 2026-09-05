<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Phase9ServerHandler;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Routing\WebReleaseCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

function phase9ServerRemove(string $directory): void
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

$repository = dirname(__DIR__);
$app = getenv('PHASE9_SERVER_APP') ?: $repository . '/build/phase9-server-app';
$app = rtrim($app, DIRECTORY_SEPARATOR);
phase9ServerRemove($app);

foreach (['bootstrap/cache', 'public', 'routes'] as $path) {
    $directory = $app . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create Phase 9 server fixture directory "%s".', $directory));
    }
}

$routeSource = <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Phase9ServerHandler;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/json', [Phase9ServerHandler::class, 'json']);
PHP;
file_put_contents($app . '/routes/web.php', $routeSource . PHP_EOL, LOCK_EX);

$sourceConfig = [
    'app' => [
        'base_path' => $app,
        'env' => 'production',
        'debug' => false,
    ],
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
$configCache = $app . '/bootstrap/cache/config';
$configLoader = new ConfigLoader();
$normalizedConfig = $configLoader->load($sourceConfig + ['_config_cache' => false]);
$configLoader->writeCache(
    $normalizedConfig,
    $configCache,
    ConfigLoader::TYPE_SINGLE,
    $app,
);
$config = $sourceConfig + ['_config_cache' => $configCache];

$manifest = $app . '/bootstrap/cache/release.json';
$release = new WebReleaseCompiler()->compile(
    $config,
    $app . '/bootstrap/cache/intermix.php',
    $app . '/bootstrap/cache/router.php',
    $manifest,
    [],
);

$trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;
if (!is_string($trustedSha256) || preg_match('/^[a-f0-9]{64}$/D', $trustedSha256) !== 1) {
    throw new RuntimeException('Phase 9 server fixture did not produce a trusted release manifest identity.');
}
$skipped = $release['intermix']['skipped'] ?? null;
if (!is_array($skipped) || $skipped !== []) {
    throw new RuntimeException('Phase 9 server fixture must compile without dynamic InterMix islands.');
}

file_put_contents(
    $app . '/config.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n",
    LOCK_EX,
);
file_put_contents(
    $app . '/trust.php',
    "<?php\n\ndeclare(strict_types=1);\n\nreturn " . var_export($trustedSha256, true) . ";\n",
    LOCK_EX,
);

$autoload = $repository . '/vendor/autoload.php';
$frontController = sprintf(<<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseRuntime;

require %s;

$config = require dirname(__DIR__) . '/config.php';
$trustedManifestSha256 = require dirname(__DIR__) . '/trust.php';

WebReleaseRuntime::loadPrevalidated(
    $config,
    dirname(__DIR__) . '/bootstrap/cache/release.json',
    $trustedManifestSha256,
    foundationCapabilities: [],
)->server->handle();
PHP, var_export($autoload, true));
file_put_contents($app . '/public/index.php', $frontController . PHP_EOL, LOCK_EX);

$opcacheProbe = <<<'PHP'
<?php

declare(strict_types=1);

header('Content-Type: application/json');
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;

echo json_encode([
    'php_version' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'opcache_enabled' => is_array($status) && ($status['opcache_enabled'] ?? false) === true,
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
PHP;
file_put_contents($app . '/public/opcache.php', $opcacheProbe . PHP_EOL, LOCK_EX);

$result = [
    'schema' => 1,
    'app' => $app,
    'document_root' => $app . '/public',
    'config_cache' => $configCache,
    'manifest' => $manifest,
    'trusted_manifest_sha256' => $trustedSha256,
    'intermix_skipped' => $skipped,
    'handler' => Phase9ServerHandler::class . '::json',
];

$output = getenv('PHASE9_SERVER_COMPILE_OUTPUT') ?: $repository . '/build/phase9-server-compile.json';
file_put_contents(
    $output,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX,
);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
