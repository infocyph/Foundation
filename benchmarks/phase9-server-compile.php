<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Phase9ServerHandler;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Routing\WebReleaseCompiler;

require dirname(__DIR__) . '/vendor/autoload.php';

$repository = dirname(__DIR__);
$app = getenv('PHASE9_SERVER_APP') ?: $repository . '/build/phase9-server-app';
$app = rtrim($app, DIRECTORY_SEPARATOR);
\Infocyph\Foundation\Benchmarks\Support\BenchmarkSupport::removeDirectory($app);

foreach (['bootstrap/cache', 'public', 'routes'] as $path) {
    $directory = $app . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    if (!mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(sprintf('Unable to create Phase 9 server fixture directory "%s".', $directory));
    }
}

$routeSource = <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Benchmarks\Phase9NativeReadHandler;
use Infocyph\Foundation\Benchmarks\Phase9ServerHandler;
use Infocyph\Webrick\Router\Facade\Router;

Router::get('/json', [Phase9ServerHandler::class, 'json']);
Router::get('/native', [Phase9NativeReadHandler::class, 'read']);
Router::get('/failure', [Phase9ServerHandler::class, 'failure']);
Router::get('/slow', [Phase9ServerHandler::class, 'slow']);
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

$profile = getenv('FOUNDATION_HOST_WORKLOAD_PROFILE') ?: 'json-minimal';

if (!in_array($profile, ['json-minimal', 'native-read'], true)) {
    throw new RuntimeException('Unsupported native HTTP benchmark profile.');
}

if ($profile === 'native-read') {
    foreach (['storage', 'storage/cache'] as $folder) {
        $target = $app . '/' . $folder;

        if (!is_dir($target) && !mkdir($target, 0775, true)) {
            throw new RuntimeException('Unable to initialize benchmark storage.');
        }
    }

    $db = new \Infocyph\DBLayer\Connection\Connection(
        \Infocyph\DBLayer\Connection\ConnectionConfig::fromArray([
            'driver' => 'sqlite',
            'database' => $app . '/storage/benchmark.sqlite',
        ]),
    );

    try {
        $db->execute('CREATE TABLE benchmark_items (id INTEGER PRIMARY KEY, item_value TEXT NOT NULL)');
        $db->execute('INSERT INTO benchmark_items (id, item_value) VALUES (?, ?)', [42, 'value-42']);
    } finally {
        $db->disconnect();
    }

    if (!\Infocyph\CacheLayer\Cache\Cache::file(
        'foundation-host',
        $app . '/storage/cache',
    )->set('item-42', 'cached-42')) {
        throw new RuntimeException('Unable to initialize native CacheLayer fixture.');
    }

    $credential = password_hash('foundation-native-fixture', PASSWORD_BCRYPT, ['cost' => 4]);

    if (file_put_contents($app . '/storage/credential.hash', $credential, LOCK_EX) === false) {
        throw new RuntimeException('Unable to initialize benchmark credential digest.');
    }
}

$manifest = $app . '/bootstrap/cache/release.json';
$runwireEnabled = getenv('FOUNDATION_HOST_RUNWIRE') === '1';
$selectedCapabilities = $runwireEnabled ? ['runwire'] : [];
$release = new WebReleaseCompiler()->compile(
    $config,
    $app . '/bootstrap/cache/intermix.php',
    $app . '/bootstrap/cache/router.php',
    $manifest,
    $selectedCapabilities,
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

if ($runwireEnabled) {
    // Native Runwire owns the listener, worker context and lifecycle.
    // Build Foundation only after Runwire supplies the actual worker context.
    $nativeEntry = <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\Runwire\Runtime;
use Infocyph\Runwire\Runtime\RuntimeApplicationFactoryInterface;
use Infocyph\Runwire\Runtime\RuntimeApplicationInterface;
use Infocyph\Runwire\RuntimeContext;
use Infocyph\Runwire\Server;

require %s;

$address = getenv('FOUNDATION_HOST_LISTEN') ?: '127.0.0.1:18781';
$workers = (int) (getenv('FOUNDATION_HOST_WORKERS') ?: 1);
$factory = new class implements RuntimeApplicationFactoryInterface {
    public function create(RuntimeContext $runtime): RuntimeApplicationInterface
    {
        $release = WebReleaseRuntime::loadPrevalidated(
            require __DIR__ . '/config.php',
            __DIR__ . '/bootstrap/cache/release.json',
            require __DIR__ . '/trust.php',
            foundationCapabilities: ['runwire'],
        );

        return $release->runwireApplication($runtime);
    }
};

Runtime::create()
    ->listen(Server::httpApplicationFactory($address, $factory)->withWorkers($workers))
    ->run();
PHP;
    file_put_contents(
        $app . '/runwire-server.php',
        sprintf($nativeEntry, var_export($autoload, true)) . PHP_EOL,
        LOCK_EX,
    );
}

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
    'native_runwire' => $runwireEnabled,
    'workload_profile' => $profile,
];

$output = getenv('PHASE9_SERVER_COMPILE_OUTPUT') ?: $repository . '/build/phase9-server-compile.json';
file_put_contents(
    $output,
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL,
    LOCK_EX,
);
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);
