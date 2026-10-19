<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\RuntimeMode;
use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Runtime\ExecutionId;
use Infocyph\Foundation\Runtime\GeneratedRuntime;
use Infocyph\Foundation\Runtime\GeneratedRuntimeCompiler;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'Infocyph\\Webrick\\')) {
        throw new LogicException('Non-web execution attempted to load ' . $class);
    }
}, prepend: true);

$mode = RuntimeMode::from($argv[1]);
$boot = $argv[2];
$project = $argv[3];
$capabilities = ['auth', 'cache', 'database', 'filesystem', 'session'];
$config = [
    'base_path' => $project,
    '_config_cache' => false,
    'app' => ['env' => 'testing', 'capabilities' => $capabilities],
    'auth' => ['oauth' => ['enabled' => true]],
    'cache' => [
        'default' => 'memory',
        'default_counter' => 'unavailable',
        'stores' => ['memory' => ['driver' => 'memory']],
        'counters' => ['unavailable' => ['driver' => 'redis', 'dsn' => 'redis://127.0.0.1:1']],
    ],
    'session' => ['driver' => 'array', 'lock' => ['enabled' => false]],
];

if ($boot === 'development') {
    $app = Foundation::{$mode->value}($config)->boot();
} else {
    $artifact = $project . '/bootstrap/cache/runtime.php';
    $report = new GeneratedRuntimeCompiler()->compile($config, $mode, $artifact, $capabilities);
    $runtime = $boot === 'validated'
        ? GeneratedRuntime::load($config, $mode, $artifact, $capabilities)
        : GeneratedRuntime::loadPrevalidated($config, $mode, $artifact, $report['metadata_sha256'], $report['digest'], $capabilities);
    $app = $runtime->application;
}

$httpServices = [
    Infocyph\Foundation\Http\HttpKernel::class,
    Infocyph\Foundation\Routing\RouteFileLoader::class,
    Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver::class,
    Infocyph\Foundation\Session\Middleware\SessionMiddleware::class,
    Infocyph\Foundation\Session\Middleware\CsrfMiddleware::class,
    Infocyph\Foundation\Filesystem\FilesystemResponseFactory::class,
    Infocyph\Foundation\Filesystem\FilesystemUploadRequestHandler::class,
    Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpInput::class,
    Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpResponseFactory::class,
    Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpThrottleFactory::class,
    Infocyph\Foundation\Auth\OAuth\Http\OAuthHttpHandler::class,
    Infocyph\Foundation\Auth\OAuth\Http\OAuthAuthorizationController::class,
    Infocyph\Webrick\Middleware\Throttle\AtomicCounterInterface::class,
];
foreach ($httpServices as $id) {
    if ($app->has($id)) {
        throw new LogicException('Non-web graph exposes HTTP service ' . $id);
    }
}

$executions = [];
for ($iteration = 0; $iteration < 2; $iteration++) {
    $executions[] = $app->execution()->run(static function (ExecutionId $id) use ($app): string {
        $app->make(AuthManager::class);
        $cache = $app->make(CacheManager::class)->store();
        $cache->set('runtime-isolation', 'ok');
        if ($cache->get('runtime-isolation') !== 'ok') {
            throw new LogicException('Native non-web cache operation failed.');
        }

        return (string) $id;
    });
}

$loaded = array_filter(
    [...get_declared_classes(), ...get_declared_interfaces(), ...get_declared_traits()],
    static fn(string $class): bool => str_starts_with($class, 'Infocyph\\Webrick\\'),
);
fwrite(STDOUT, json_encode([
    'runtime' => $app->runtimeMode()->value,
    'executions' => $executions,
    'webrick' => array_values($loaded),
], JSON_THROW_ON_ERROR));
