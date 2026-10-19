<?php

declare(strict_types=1);

use Infocyph\Foundation\Application\FoundationBuildContext;
use Infocyph\Foundation\Application\ServiceProvider;
use Infocyph\Foundation\Routing\WebReleaseCompiler;
use Infocyph\Foundation\Routing\WebReleaseRuntime;
use Infocyph\InterMix\DI\ContainerBuilder;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Infocyph\Webrick\Router\Build\CompiledRouterArtifact;
use Infocyph\Webrick\Router\Build\RouteCapability;
use Infocyph\Webrick\Router\Url\UrlGeneratorRegistry;

final class FoundationReleaseCompositionCounter extends ServiceProvider
{
    public static int $contributions = 0;

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($builder, $context);
        self::$contributions++;
    }
}

final class FoundationReleaseSourceHookProvider extends ServiceProvider
{
    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        unset($context);
        $builder->onScopeLeave('request', static function (): void {});
    }
}

final class FoundationUnusedBackendProvider extends ServiceProvider
{
    public static int $activations = 0;
    public static int $mailActivations = 0;
    public static int $filesystemActivations = 0;

    /** @var list<string> */
    public static array $unusedActivations = [];

    public static function http(): \Infocyph\TalkingBytes\Http\HttpClient
    {
        self::$unusedActivations[] = 'http';
        throw new LogicException('Unused HTTP integration was activated.');
    }

    public static function messaging(): \Infocyph\Omnibus\MessageBus
    {
        self::$unusedActivations[] = 'messaging';
        throw new LogicException('Unused message bus was activated.');
    }

    public static function cache(): \Infocyph\Foundation\Cache\CacheLayerFactory
    {
        self::$unusedActivations[] = 'cache';
        throw new LogicException('Unused cache integration was activated.');
    }

    public static function mail(): \Infocyph\Foundation\Notifications\MailNotificationChannel
    {
        ++self::$mailActivations;
        throw new LogicException('Unused mail integration was activated.');
    }

    public static function filesystem(): \Infocyph\Foundation\Filesystem\StorageRegistry
    {
        ++self::$filesystemActivations;
        throw new LogicException('Unused filesystem integration was activated.');
    }

    public static function database(): \Infocyph\Foundation\Database\DBLayerFactory
    {
        ++self::$activations;

        throw new LogicException('Unused database factory was activated.');
    }

    public function contribute(ContainerBuilder $builder, FoundationBuildContext $context): void
    {
        if (($context->config['test']['allow_database'] ?? false) !== true) {
            $builder->unbind(\Infocyph\Foundation\Database\DBLayerFactory::class);
            $builder->factory(
                \Infocyph\Foundation\Database\DBLayerFactory::class,
                \Infocyph\InterMix\DI\Support\FactoryDefinition::staticFactory(self::class, 'database'),
            );
        }
        foreach ([
            \Infocyph\Foundation\Notifications\MailNotificationChannel::class => 'mail',
            \Infocyph\Foundation\Filesystem\StorageRegistry::class => 'filesystem',
            \Infocyph\TalkingBytes\Http\HttpClient::class => 'http',
        ] as $id => $method) {
            $builder->unbind($id);
            $builder->factory($id, \Infocyph\InterMix\DI\Support\FactoryDefinition::staticFactory(self::class, $method));
        }
        if (($context->config['test']['allow_messaging'] ?? false) !== true) {
            $builder->unbind(\Infocyph\Omnibus\MessageBus::class);
            $builder->factory(\Infocyph\Omnibus\MessageBus::class, \Infocyph\InterMix\DI\Support\FactoryDefinition::staticFactory(self::class, 'messaging'));
        }
        $builder->factory(FoundationColdMessageHandler::class, \Infocyph\InterMix\DI\Support\FactoryDefinition::construct(FoundationColdMessageHandler::class));
        $builder->factory(FoundationColdGrpcHandler::class, \Infocyph\InterMix\DI\Support\FactoryDefinition::construct(FoundationColdGrpcHandler::class));
        if (($context->config['test']['allow_cache'] ?? false) !== true) {
            $builder->unbind(\Infocyph\Foundation\Cache\CacheLayerFactory::class);
            $builder->factory(\Infocyph\Foundation\Cache\CacheLayerFactory::class, \Infocyph\InterMix\DI\Support\FactoryDefinition::staticFactory(self::class, 'cache'));
        }
        $builder->factory(FoundationColdNotificationChannel::class, \Infocyph\InterMix\DI\Support\FactoryDefinition::construct(FoundationColdNotificationChannel::class));

    }
}

final class FoundationColdMessageHandler
{
    public function __invoke(stdClass $message): string
    {
        unset($message);
        return 'handled';
    }
}

final class FoundationColdGrpcHandler implements \Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundHandlerInterface
{
    public function handle(\Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundRequest $request): \Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse
    {
        return \Infocyph\TalkingBytes\Grpc\Receiver\GrpcInboundResponse::ok($request->message);
    }
}

final class FoundationColdNotification implements \Infocyph\Foundation\Notifications\Notification, \Infocyph\Foundation\Notifications\NotificationRecipient
{
    public function channels(\Infocyph\Foundation\Notifications\NotificationRecipient $recipient): array
    {
        unset($recipient);
        return ['custom' => 'hello'];
    }

    public function routeNotificationFor(string $channel, \Infocyph\Foundation\Notifications\Notification $notification): mixed
    {
        unset($channel, $notification);
        return null;
    }
}

final class FoundationColdNotificationChannel implements \Infocyph\Foundation\Notifications\NotificationChannel
{
    public function send(\Infocyph\Foundation\Notifications\NotificationRecipient $recipient, \Infocyph\Foundation\Notifications\Notification $notification, mixed $payload): mixed
    {
        unset($recipient, $notification, $payload);
        return 'custom-ok';
    }
}

final class FoundationCompiledReleaseHandler
{
    public function __invoke(): Response
    {
        return Response::json(['compiled' => true]);
    }
}

final class FoundationCompiledReleaseFailureHandler
{
    public function __invoke(): never
    {
        throw new RuntimeException('sensitive-foundation-runtime-failure');
    }
}

final class FoundationCompiledReleaseMiddleware
{
    public function __invoke(Request $request, Closure $next): Response
    {
        unset($request);

        return $next();
    }
}

function foundationWebReleasePlainHandler(): Response
{
    return Response::json(['plain' => true]);
}

it('loads trusted web artifacts without composing providers and validates untrusted graphs once', function (bool $prevalidated): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'providers' => ['web' => [FoundationReleaseCompositionCounter::class]],
        'router' => ['files' => ['web.php'], 'middleware' => ['globals' => ['pre' => [], 'post' => []]]],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: [],
        );
        FoundationReleaseCompositionCounter::$contributions = 0;

        $runtime = $prevalidated
            ? WebReleaseRuntime::loadPrevalidated($config, $manifest, $release['release_runtime_manifest_sha256'], foundationCapabilities: [])
            : WebReleaseRuntime::load($config, $manifest, foundationCapabilities: []);

        expect(FoundationReleaseCompositionCounter::$contributions)->toBe($prevalidated ? 0 : 1);

        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $release['release_runtime_manifest_sha256'],
            foundationCapabilities: ['database'],
        ))->toThrow(RuntimeException::class, 'capability identity mismatch');
        $changedConfig = $config;
        $changedConfig['app']['env'] = 'staging';
        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $changedConfig,
            $manifest,
            $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        ))->toThrow(RuntimeException::class, 'configuration identity mismatch');
        $changedConfig = $config;
        $changedConfig['app']['debug'] = true;
        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $changedConfig,
            $manifest,
            $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        ))->toThrow(RuntimeException::class, 'configuration identity mismatch');

        $response = $runtime->kernel->handle(Request::fake(uri: 'https://release.test/plain'));
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('{"plain":true}');
    } finally {
        FoundationReleaseCompositionCounter::$contributions = 0;
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
})->with([false, true]);

it('rejects web releases requiring source runtime hooks before deployment', function (): void {
    $project = foundationWebReleaseProject();
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production'],
        '_config_cache' => false,
        'providers' => ['web' => [FoundationReleaseSourceHookProvider::class]],
        'router' => ['files' => ['web.php']],
    ];

    try {
        expect(fn() => new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $project . '/bootstrap/cache/release.json',
            capabilities: [],
        ))->toThrow(\Infocyph\InterMix\Exceptions\ContainerException::class, 'requires its frozen fallback graph');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('keeps unused backend integrations cold throughout unrelated flows', function (string $mode, string $flow): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $capabilities = ['auth', 'cache', 'session', 'database', 'validation', 'filesystem', 'notifications', 'communication', 'messaging', 'security', 'otp', 'passkeys'];
    $config = [
        'app' => ['base_path' => $project, 'env' => 'testing'],
        '_config_cache' => false,
        'providers' => ['web' => [FoundationUnusedBackendProvider::class]],
        'auth' => ['drivers' => ['storage' => 'database']],
        'notifications' => ['channels' => ['custom' => FoundationColdNotificationChannel::class]],
        'test' => [
            'allow_cache' => $flow === 'cache',
            'allow_database' => in_array($flow, ['database-metadata', 'database-cached-metadata'], true),
            'allow_messaging' => $flow === 'sync-message',
        ],
        'messaging' => [
            'durable' => ['enabled' => true, 'failure_store' => 'database'],
            'default_route' => ['transport' => 'sync'],
            'handlers' => [stdClass::class => FoundationColdMessageHandler::class],
        ],
        'communication' => ['grpc' => ['inbound' => ['handlers' => [
            '/cold.Service/Echo' => FoundationColdGrpcHandler::class,
            '/cold.Service/Unused' => \Infocyph\Foundation\Database\DBLayerFactory::class,
        ]]], 'webhooks' => ['inbound' => ['default' => [
            'secret' => 'cold-webhook-test-secret',
            'replay' => ['enabled' => true, 'store' => 'memory'],
        ]]]],
        'database' => [
            'default' => 'unused',
            'connections' => ['unused' => ['driver' => 'sqlite', 'database' => $project . '/never-open.sqlite']],
            'pool' => ['enabled' => true, 'min_connections' => 0, 'max_connections' => 1],
            'query_cache' => ['enabled' => $flow === 'database-cached-metadata', 'store' => 'memory'],
        ],
        'cache' => ['default' => 'memory', 'stores' => ['memory' => ['driver' => 'memory']]],
        'session' => ['driver' => 'database', 'lock' => ['enabled' => true]],
        'router' => ['files' => ['web.php']],
    ];
    FoundationUnusedBackendProvider::$activations = 0;
    FoundationUnusedBackendProvider::$mailActivations = 0;
    FoundationUnusedBackendProvider::$filesystemActivations = 0;
    FoundationUnusedBackendProvider::$unusedActivations = [];

    try {
        if ($mode === 'development') {
            $application = new \Infocyph\Foundation\Routing\WebGraphFactory()->compose($config, $capabilities)->application;
            $application->boot();
            $container = $application->runtime();
        } else {
            $release = new WebReleaseCompiler()->compile(
                $config,
                $project . '/bootstrap/cache/intermix.php',
                $project . '/bootstrap/cache/router.php',
                $manifest,
                capabilities: $capabilities,
            );
            $container = ($mode === 'prevalidated'
                ? WebReleaseRuntime::loadPrevalidated($config, $manifest, $release['release_runtime_manifest_sha256'], foundationCapabilities: $capabilities)
                : WebReleaseRuntime::load($config, $manifest, foundationCapabilities: $capabilities))->container;
        }
        $container->withinScope('webrick.request', static function (\Psr\Container\ContainerInterface $scope) use ($flow): void {
            match ($flow) {
                'grpc' => expect($scope->get(\Infocyph\TalkingBytes\Grpc\GrpcInboundDispatcher::class)->receive('/cold.Service/Echo', ['ok' => true])->status->value)->toBe(0),
                'auth-store' => expect($scope->get(\Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface::class))->toBeInstanceOf(\Infocyph\Foundation\Auth\Contract\Storage\AccountProviderInterface::class),
                'principal-invalid' => expect($scope->get(\Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver::class)->resolve(Request::fake(headers: ['Authorization' => 'Bearer malformed'])))->toBeNull(),
                'serializer' => expect($scope->get(\Infocyph\Omnibus\Serialization\EnvelopeSerializer::class))->toBeInstanceOf(\Infocyph\Omnibus\Serialization\EnvelopeSerializer::class),
                'consumer-empty' => expect($scope->get(\Infocyph\Omnibus\Consumer\Consumer::class)->run()->received)->toBe(0),
                'webhook-rejected' => expect(fn() => $scope->get(\Infocyph\TalkingBytes\Webhook\WebhookReceiver::class)->receive('{}', [
                    'X-TB-Event' => 'probe', 'X-TB-Delivery' => 'cold-probe',
                ]))->toThrow(RuntimeException::class, 'Webhook verification failed'),
                'database-metadata', 'database-cached-metadata' => expect($scope->get(\Infocyph\DBLayer\Connection\Connection::class)->isConnected())->toBeFalse(),
                'sync-message' => expect($scope->get(\Infocyph\Omnibus\MessageBus::class)->dispatch(new stdClass())->last(\Infocyph\Omnibus\Envelope\HandledStamp::class)?->result)->toBe('handled'),
                'notification' => expect($scope->get(\Infocyph\Foundation\Notifications\NotificationDispatcher::class)->send(
                    new FoundationColdNotification(),
                    new FoundationColdNotification(),
                ))->toBe(['custom' => 'custom-ok']),
                'cache' => expect($scope->get(\Infocyph\Foundation\Cache\CacheManager::class)->store()->set('probe', 'ok'))->toBeTrue(),
                'principal' => expect($scope->get(\Infocyph\Foundation\Http\Resolver\RequestPrincipalResolver::class)->resolve(Request::fake()))->toBeNull(),
                'session' => expect($scope->get(\Infocyph\Foundation\Session\Middleware\SessionMiddleware::class)(
                    Request::fake(),
                    static fn(): Response => Response::json(['ok' => true]),
                )->getStatusCode())->toBe(200),
                'validation' => expect($scope->get(\Infocyph\Foundation\Validation\ValidatorFactory::class)->makeRules([
                    'email' => 'required|email',
                ])->validate(['email' => 'ada@example.test'])->fails())->toBeFalse(),
                default => expect(Response::json(['ok' => true])->getStatusCode())->toBe(200),
            };
        });
        expect(FoundationUnusedBackendProvider::$activations)->toBe(0)
            ->and(FoundationUnusedBackendProvider::$mailActivations)->toBe(0)
            ->and(FoundationUnusedBackendProvider::$filesystemActivations)->toBe(0)
            ->and(FoundationUnusedBackendProvider::$unusedActivations)->toBe([])
            ->and(is_file($project . '/never-open.sqlite'))->toBeFalse();
    } finally {
        FoundationUnusedBackendProvider::$activations = 0;
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
})->with(['development', 'validated', 'prevalidated'])->with(['plain', 'cache', 'principal', 'principal-invalid', 'auth-store', 'session', 'validation', 'notification', 'sync-message', 'database-metadata', 'database-cached-metadata', 'serializer', 'consumer-empty', 'webhook-rejected', 'grpc']);

it('compiles and boots a trusted route-first Webrick release without live router construction', function (): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $log = $project . '/storage/logs/web-release.log';
    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'logging' => [
            'driver' => 'file',
            'level' => 'warning',
            'path' => $log,
            'exceptions' => [
                'include_message' => false,
                'include_trace' => false,
            ],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => ['pre' => [], 'post' => []],
            ],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: [],
        );
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;

        expect($trustedSha256)->toBeString()
            ->and($trustedSha256)->toMatch('/^[a-f0-9]{64}$/D')
            ->and($release['intermix']['skipped'] ?? null)->toBe([]);

        $compiled = realpath($intermix);
        expect($compiled)->toBeString();
        $metadata = json_decode(
            (string) file_get_contents(dirname($compiled) . '/manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        expect($metadata['fallback']['required'] ?? null)->toBeFalse()
            ->and($metadata['fallback']['identity_required'] ?? null)->toBeFalse();

        $payload = require $router;
        expect($payload)->toBeArray();
        $artifact = CompiledRouterArtifact::fromPayload($payload);
        $plansByPath = [];
        foreach ($artifact->routes() as $route) {
            $plansByPath[$route->getPath()] = $artifact->planForIndex($route->getIndex());
        }

        $plainPlan = $plansByPath['/plain'] ?? null;
        expect($plainPlan)->not->toBeNull()
            ->and($plainPlan->requiresRequest())->toBeFalse()
            ->and($plainPlan->requiresScope())->toBeFalse()
            ->and(RouteCapability::has($plainPlan->capabilities, RouteCapability::MIDDLEWARE))->toBeFalse();

        $middlewarePlan = $plansByPath['/middleware'] ?? null;
        expect($middlewarePlan)->not->toBeNull()
            ->and($middlewarePlan->requiresRequest())->toBeTrue()
            ->and($middlewarePlan->requiresScope())->toBeTrue()
            ->and(RouteCapability::has($middlewarePlan->capabilities, RouteCapability::MIDDLEWARE))->toBeTrue();

        $runtime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $trustedSha256,
            foundationCapabilities: [],
        );
        expect(UrlGeneratorRegistry::frozen())->toBeTrue()
            ->and(UrlGeneratorRegistry::get()->urlFor('plain.show'))->toBe('/plain');

        $response = $runtime->kernel->handle(Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/compiled',
        ));
        expect($response->getStatusCode())->toBe(200)
            ->and((string) $response->getBody())->toBe('{"compiled":true}');

        $missing = $runtime->kernel->handle(Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/missing',
        ));
        expect($missing->getStatusCode())->toBe(404);

        $methodNotAllowed = $runtime->kernel->handle(Request::fake(
            method: 'POST',
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/compiled',
        ));
        expect($methodNotAllowed->getStatusCode())->toBe(405)
            ->and($methodNotAllowed->getHeaderLine('Allow'))->toContain('GET');

        $failure = $runtime->kernel->handle(Request::fake(
            headers: ['Accept' => 'application/json', 'Host' => 'release.test'],
            uri: 'https://release.test/failure',
        ));
        expect($failure->getStatusCode())->toBe(500)
            ->and((string) $failure->getBody())->toContain('Internal Server Error')
            ->and((string) $failure->getBody())->not->toContain('sensitive-foundation-runtime-failure')
            ->and(is_file($log))->toBeTrue();
        $logged = file_get_contents($log);
        expect($logged)->toBeString()
            ->and($logged)->toContain(RuntimeException::class)
            ->and($logged)->not->toContain('sensitive-foundation-runtime-failure');

        expect(fn() => WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            str_repeat('0', 64),
            foundationCapabilities: [],
        ))->toThrow(RuntimeException::class, 'trust identity mismatch');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('compiles maintenance as an opt-in Webrick gate with bounded runtime refresh', function (): void {
    $project = foundationWebReleaseProject();
    $manifest = $project . '/bootstrap/cache/release.json';
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $maintenancePath = $project . '/storage/framework/maintenance.json';
    mkdir(dirname($maintenancePath), 0777, true);
    file_put_contents($maintenancePath, json_encode([
        'enabled' => true,
        'enabled_at' => gmdate(DATE_ATOM),
        'retry_after' => 17,
        'message' => 'Foundation maintenance window.',
    ], JSON_THROW_ON_ERROR));

    $config = [
        'app' => [
            'base_path' => $project,
            'env' => 'production',
            'debug' => false,
        ],
        '_config_cache' => false,
        'operations' => [
            'maintenance' => [
                'driver' => 'file',
                'path' => 'storage/framework/maintenance.json',
                'refresh_milliseconds' => 0,
                'retry_after' => 3600,
                'web' => ['enabled' => true],
            ],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => [
                'globals' => ['pre' => [], 'post' => []],
            ],
        ],
    ];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: [],
        );
        $trustedSha256 = $release['release_runtime_manifest_sha256'] ?? null;
        expect($trustedSha256)->toBeString()
            ->and($release['intermix']['skipped'] ?? null)->toBe([]);

        $payload = require $router;
        expect($payload)->toBeArray();
        $artifact = CompiledRouterArtifact::fromPayload($payload);
        $plainPlan = null;
        foreach ($artifact->routes() as $route) {
            if ($route->getPath() === '/plain') {
                $plainPlan = $artifact->planForIndex($route->getIndex());

                break;
            }
        }

        expect($plainPlan)->not->toBeNull()
            ->and($plainPlan->requiresRequest())->toBeFalse()
            ->and($plainPlan->requiresScope())->toBeFalse()
            ->and(RouteCapability::has($plainPlan->capabilities, RouteCapability::MIDDLEWARE))->toBeFalse()
            ->and($artifact->hasGlobalMiddleware())->toBeTrue();

        $runtime = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            $trustedSha256,
            foundationCapabilities: [],
        );
        $request = Request::fake(
            headers: ['Host' => 'release.test'],
            uri: 'https://release.test/plain',
        );
        $maintenance = $runtime->kernel->handle($request);
        expect($maintenance->getStatusCode())->toBe(503)
            ->and($maintenance->getHeaderLine('Retry-After'))->toBe('17');

        unlink($maintenancePath);
        $available = $runtime->kernel->handle($request);
        expect($available->getStatusCode())->toBe(200)
            ->and((string) $available->getBody())->toBe('{"plain":true}');
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

function foundationWebReleaseProject(): string
{
    $project = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
        . 'foundation-web-release-' . bin2hex(random_bytes(5));
    mkdir($project . '/routes', 0777, true);
    mkdir($project . '/bootstrap/cache', 0777, true);
    file_put_contents($project . '/routes/web.php', <<<'PHP'
<?php

declare(strict_types=1);

use Infocyph\Webrick\Router\Facade\Router;

Router::get('/plain', 'foundationWebReleasePlainHandler', ['name' => 'plain.show']);
Router::get('/middleware', 'foundationWebReleasePlainHandler', [
    'name' => 'middleware.show',
    'middleware' => [FoundationCompiledReleaseMiddleware::class],
]);
Router::get('/compiled', FoundationCompiledReleaseHandler::class, ['name' => 'compiled.show']);
Router::get('/failure', FoundationCompiledReleaseFailureHandler::class);
PHP);

    return $project;
}

function foundationWebReleaseRemove(string $directory): void
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

it('boots an explicitly compiled Runwire web host through native Webrick and InterMix', function (): void {
    $project = foundationWebReleaseProject();
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
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $manifest = $project . '/bootstrap/cache/release.json';
    $capabilities = ['runwire'];

    try {
        $release = new WebReleaseCompiler()->compile(
            $config,
            $intermix,
            $router,
            $manifest,
            capabilities: $capabilities,
        );
        expect($release['intermix']['skipped'] ?? null)->toBe([]);

        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $release['release_runtime_manifest_sha256'],
            foundationCapabilities: $capabilities,
        );
        $staleProcess = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            new \Infocyph\Runwire\RuntimeCapabilities(
                \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
            ),
            'stale-process',
            pid: (int) getmypid() + 1,
        );
        expect(fn(): mixed => $loaded->runwireApplication($staleProcess))
            ->toThrow(LogicException::class, 'different process');

        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $host = $loaded->runwireApplication($runtime);

        expect($host)->toBeInstanceOf(\Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication::class);
        $host->start();
        expect($host->healthy())->toBeTrue();
        $host->shutdown();
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('accepts an exact host runtime through Core inputs without an optional runtime feature', function (): void {
    $project = foundationWebReleaseProject();
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
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $intermix = $project . '/bootstrap/cache/intermix.php';
    $router = $project . '/bootstrap/cache/router.php';
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $release = new WebReleaseCompiler()->compile($config, $intermix, $router, $manifest, []);
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $release['release_runtime_manifest_sha256'],
            foundationCapabilities: [],
        );

        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $host = $loaded->runwireApplication($runtime);
        $host->start();
        expect($host->healthy())->toBeTrue();
        $host->shutdown();
        expect(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBeNull();
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

final class FoundationRunwireReleaseContextHandler
{
    public static function identity(\Infocyph\Runwire\RequestContext $runwireRequest): Response
    {
        return Response::json(['request' => $runwireRequest->requestId]);
    }

    public static function show(
        \Infocyph\Runwire\RuntimeContext $runtime,
        \Infocyph\Runwire\RequestContext $runwireRequest,
        \Infocyph\Webrick\Request\Request $request,
        \Infocyph\Foundation\Database\DBLayerFactory $factory,
        \Infocyph\Omnibus\MessageBus $bus,
    ): \Infocyph\Webrick\Response\Response {
        // Exercise the published selected-library APIs inside real compiled
        // Webrick dispatch, not merely in standalone library contract tests.
        $epicrypt = new \Infocyph\Epicrypt\Integration\Runwire\RunwireExecutionContext($runtime, $runwireRequest);
        $pathwise = new \Infocyph\Pathwise\Integration\Runwire\RunwireExecutionContext($runtime, $runwireRequest);
        $reqshield = new \Infocyph\ReqShield\Support\RunwireExecution($runtime, $runwireRequest);
        $uid = new \Infocyph\UID\Runtime\RunwireBinding($runtime, $runwireRequest);
        $epicrypt->checkpoint();
        $pathwise->checkpoint();
        $reqshield->checkpoint();
        $uid->assertActive();
        \Infocyph\OTP\Support\RunwireState::checkpoint(
            new \Infocyph\CacheLayer\Integration\Runwire\RunwireExecutionContext($runtime, $runwireRequest),
        );

        $connection = $factory->connection();
        $database = $connection->runwireBinding();
        $messageContext = [$bus->runwireBinding()->runtime(), $bus->runwireBinding()->request()];
        $dispatched = $bus->dispatch(new stdClass());
        $queried = $connection->scalar('SELECT 1');

        return \Infocyph\Webrick\Response\Response::json([
            'mode' => $runtime->mode,
            'request' => $runwireRequest->requestId,
            'path' => $request->getUri()->getPath(),
            'native_modules' => [
                'arraykit' => \Infocyph\ArrayKit\Collection\LazyCollection::from([1, 2])
                    ->withRunwire($runtime, $runwireRequest)->all(),
                'dblayer' => $database === [
                    'runtime' => $runtime, 'request' => $runwireRequest, 'scope' => null,
                ],
                'omnibus' => $messageContext === [$runtime, $runwireRequest],
                'talkingbytes' => (new \Infocyph\TalkingBytes\Integration\Runwire\RunwireBinding(
                    $runtime,
                    $runwireRequest,
                ))->deadline() === null,
                'ordinary_calls' => $queried === 1 && $dispatched instanceof \Infocyph\Omnibus\Envelope\Envelope,
            ],
        ]);
    }
}

it('sends the exact host Runwire request through compiled Webrick and native InterMix', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-context', [FoundationRunwireReleaseContextHandler::class, 'show']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
        'messaging' => ['default_route' => ['transport' => 'memory']],
        'logging' => [
            'driver' => 'file',
            'level' => 'debug',
            'path' => $project . '/storage/logs/runwire-diagnostic.log',
            'exceptions' => ['include_message' => true, 'include_trace' => false],
        ],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire', 'database', 'messaging'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['messaging', 'database', 'runwire'],
        );
        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $request = \Infocyph\Runwire\RequestContext::create($runtime, requestId: 'foundation-native-request');
        $native = new \Infocyph\Runwire\Http\HttpRequest(
            method: 'GET',
            target: '/runwire-context',
            version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
            headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
            body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
            context: $request,
        );
        $writer = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
        $host = $loaded->runwireApplication($runtime);
        $host->start();

        try {
            $host->handle($native, $writer, completeResponse: true);
            if ($writer->status !== 200) {
                $logPath = $project . '/storage/logs/runwire-diagnostic.log';
                $diagnostics = is_file($logPath) ? (string) file_get_contents($logPath) : '(no log file)';

                throw new RuntimeException('Compiled Runwire request failed: ' . $writer->body . ' ' . $diagnostics);
            }

            expect(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBeNull()
                ->and(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::runtime())->toBeNull()
                ->and($writer->status)->toBe(200)
                ->and($writer->endCalls)->toBe(1)
                ->and($request->completed())->toBeTrue()
                ->and(json_decode($writer->body, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'mode' => $runtime->mode,
                    'request' => 'foundation-native-request',
                    'path' => '/runwire-context',
                    'native_modules' => [
                        'arraykit' => [1, 2],
                        'dblayer' => true,
                        'omnibus' => true,
                        'talkingbytes' => true,
                        'ordinary_calls' => true,
                    ],
                ]);
        } finally {
            $host->shutdown();
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

final class FoundationRunwireInterleavedHandler
{
    public static array $observed = [];

    public static function identity(
        \Infocyph\Runwire\RuntimeContext $runtime,
        \Infocyph\Runwire\RequestContext $runwireRequest,
    ): \Infocyph\Webrick\Response\Response {
        $execution = new \Infocyph\UID\Runtime\RunwireBinding($runtime, $runwireRequest);
        $execution->assertActive();
        Fiber::suspend($runwireRequest->requestId);
        $execution->assertActive();

        return \Infocyph\Webrick\Response\Response::json([
            'request' => $runwireRequest->requestId,
            'runtime' => $runtime->mode,
        ]);
    }

    public static function show(
        \Infocyph\Runwire\RuntimeContext $runtime,
        \Infocyph\Runwire\RequestContext $runwireRequest,
        \Infocyph\Foundation\Database\DBLayerFactory $factory,
        \Infocyph\Omnibus\MessageBus $bus,
    ): \Infocyph\Webrick\Response\Response {
        $execution = new \Infocyph\UID\Runtime\RunwireBinding($runtime, $runwireRequest);
        $execution->assertActive();
        $connection = $factory->connection();
        self::$observed[$runwireRequest->requestId] = [
            'same_native_request' => $connection->runwireBinding()['request'] === $runwireRequest
                && $bus->runwireBinding()->request() === $runwireRequest,
            'database_cancelled' => false,
            'bus_cancelled' => false,
        ];
        Fiber::suspend($runwireRequest->requestId);
        if ($runwireRequest->cancelled()) {
            try {
                $connection->scalar('SELECT 1');
            } catch (\Infocyph\DBLayer\Exceptions\ConnectionException) {
                self::$observed[$runwireRequest->requestId]['database_cancelled'] = true;
            }

            try {
                $bus->dispatch(new stdClass());
            } catch (\Infocyph\Runwire\Exception\CancelledException) {
                self::$observed[$runwireRequest->requestId]['bus_cancelled'] = true;
            }
            $execution->assertActive();
        }
        $connection->scalar('SELECT 2');
        $bus->dispatch(new stdClass());

        return \Infocyph\Webrick\Response\Response::json([
            'request' => $runwireRequest->requestId,
            'runtime' => $runtime->mode,
        ]);
    }
}

it('isolates two interleaved compiled Runwire requests when one host request is cancelled', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-interleaved', [FoundationRunwireInterleavedHandler::class, 'show']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'database' => [
            'default' => 'test',
            'connections' => ['test' => ['driver' => 'sqlite', 'database' => ':memory:']],
        ],
        'messaging' => ['default_route' => ['transport' => 'memory']],
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire', 'database', 'messaging'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire', 'database', 'messaging'],
        );
        $runtime = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            new \Infocyph\Runwire\RuntimeCapabilities(
                \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
            ),
            'interleaved',
            concurrent: true,
        );
        $host = $loaded->runwireApplication($runtime);
        $first = \Infocyph\Runwire\RequestContext::create($runtime, requestId: 'interleaved-first');
        $second = \Infocyph\Runwire\RequestContext::create($runtime, requestId: 'interleaved-second');
        $firstWriter = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
        $secondWriter = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();

        $worker = static function (
            \Infocyph\Runwire\RequestContext $request,
            \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter $writer,
        ) use ($host): Fiber {
            return new Fiber(static function () use ($host, $request, $writer): void {
                $native = new \Infocyph\Runwire\Http\HttpRequest(
                    method: 'GET',
                    target: '/runwire-interleaved',
                    version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
                    headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
                    body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
                    context: $request,
                );
                $host->handle($native, $writer, completeResponse: true);
            });
        };
        $firstFiber = $worker($first, $firstWriter);
        $secondFiber = $worker($second, $secondWriter);
        $host->start();

        try {
            expect($firstFiber->start())->toBe('interleaved-first')
                ->and($secondFiber->start())->toBe('interleaved-second')
                ->and($first->completed())->toBeFalse()
                ->and($second->completed())->toBeFalse();

            $first->cancel(\Infocyph\Runwire\Runtime\Enum\CancellationReason::HOST_CANCELLED);
            $secondFiber->resume();
            $firstFiber->resume();

            expect($firstFiber->isTerminated())->toBeTrue()
                ->and($secondFiber->isTerminated())->toBeTrue()
                ->and($first->completed())->toBeTrue()
                ->and($second->completed())->toBeTrue()
                ->and($secondWriter->status)->toBe(200)
                ->and($secondWriter->endCalls)->toBe(1)
                ->and(json_decode($secondWriter->body, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'request' => 'interleaved-second',
                    'runtime' => 'interleaved',
                ])
                ->and($firstWriter->endCalls)->toBeLessThanOrEqual(1)
                ->and(FoundationRunwireInterleavedHandler::$observed['interleaved-first'])->toBe([
                    'same_native_request' => true, 'database_cancelled' => true, 'bus_cancelled' => true,
                ])
                ->and(FoundationRunwireInterleavedHandler::$observed['interleaved-second']['same_native_request'])->toBeTrue()
                ->and(\Infocyph\CacheLayer\Integration\Runwire\RunwireIntegration::current())->toBeNull();
        } finally {
            $host->shutdown();
            FoundationRunwireInterleavedHandler::$observed = [];
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

final class FoundationRunwireDeferredWriter implements \Infocyph\Runwire\Http\ResponseWriterInterface
{
    public string $body = '';

    public int $endCalls = 0;

    public int $status = 0;

    private ?Closure $drainCallback = null;

    private bool $ended = false;

    private bool $started = false;

    private ?Closure $terminalCallback = null;

    public function drain(): void
    {
        $callback = $this->drainCallback;
        $this->drainCallback = null;
        $callback?->__invoke($this);
    }

    public function end(string $finalChunk = ''): \Infocyph\Runwire\Network\WriteResult
    {
        $this->body .= $finalChunk;
        ++$this->endCalls;
        $this->ended = true;
        ($this->terminalCallback)?->__invoke($this);

        return new \Infocyph\Runwire\Network\WriteResult(
            \Infocyph\Runwire\Network\Enum\WriteState::ACCEPTED,
            0,
        );
    }

    public function isEnded(): bool
    {
        return $this->ended;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function onDrain(callable $callback): self
    {
        $this->drainCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function onTerminal(callable $callback): self
    {
        $this->terminalCallback = Closure::fromCallable($callback);

        return $this;
    }

    public function start(
        int $status = 200,
        ?\Infocyph\Runwire\Http\Headers $headers = null,
    ): \Infocyph\Runwire\Network\WriteResult {
        unset($headers);
        $this->status = $status;
        $this->started = true;

        return new \Infocyph\Runwire\Network\WriteResult(
            \Infocyph\Runwire\Network\Enum\WriteState::PRESSURED,
            1,
        );
    }

    public function write(string $chunk): \Infocyph\Runwire\Network\WriteResult
    {
        $this->body .= $chunk;

        return new \Infocyph\Runwire\Network\WriteResult(
            \Infocyph\Runwire\Network\Enum\WriteState::ACCEPTED,
            0,
        );
    }
}

it('defers Runwire response finalization until native writer drain and completes once', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-context', [FoundationRunwireReleaseContextHandler::class, 'identity']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $compiled = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $compiled['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire'],
        );
        $runtime = \Infocyph\Runwire\RuntimeContext::standalone();
        $request = \Infocyph\Runwire\RequestContext::create($runtime, requestId: 'deferred-writer');
        $native = new \Infocyph\Runwire\Http\HttpRequest(
            method: 'GET',
            target: '/runwire-context',
            version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
            headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
            body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
            context: $request,
        );
        $writer = new FoundationRunwireDeferredWriter();
        $host = $loaded->runwireApplication($runtime);
        $host->start();

        try {
            $host->handle($native, $writer, completeResponse: true);

            expect($writer->status)->toBe(200)
                ->and($writer->isStarted())->toBeTrue()
                ->and($writer->isEnded())->toBeFalse()
                ->and($writer->endCalls)->toBe(0)
                ->and($request->completed())->toBeFalse();

            $writer->drain();

            expect($writer->isEnded())->toBeTrue()
                ->and($writer->endCalls)->toBe(1)
                ->and($request->completed())->toBeTrue()
                ->and(json_decode($writer->body, true, flags: JSON_THROW_ON_ERROR)['request'])
                ->toBe('deferred-writer');

            $writer->drain();
            expect($writer->endCalls)->toBe(1);
        } finally {
            $host->shutdown();
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('rejects a distinct same-process Runwire generation without poisoning the compiled host', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-context', [FoundationRunwireReleaseContextHandler::class, 'identity']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire'],
        );
        $capabilities = new \Infocyph\Runwire\RuntimeCapabilities(
            \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
        );
        $hostRuntime = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            $capabilities,
            'generation-seven',
            generation: 7,
        );
        $otherRuntime = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            $capabilities,
            'generation-eight',
            generation: 8,
        );
        $wrong = \Infocyph\Runwire\RequestContext::create($otherRuntime);
        $right = \Infocyph\Runwire\RequestContext::create($hostRuntime);
        $native = static fn(\Infocyph\Runwire\RequestContext $context): \Infocyph\Runwire\Http\HttpRequest => new \Infocyph\Runwire\Http\HttpRequest(
            method: 'GET',
            target: '/runwire-context',
            version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
            headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
            body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
            context: $context,
        );
        $host = $loaded->runwireApplication($hostRuntime);
        $host->start();

        try {
            $badWriter = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
            expect(fn(): mixed => $host->handle($native($wrong), $badWriter, completeResponse: true))
                ->toThrow(LogicException::class, 'different runtime context')
                ->and($badWriter->endCalls)->toBe(0)
                ->and($wrong->completed())->toBeFalse();

            $goodWriter = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
            $host->handle($native($right), $goodWriter, completeResponse: true);

            expect($goodWriter->status)->toBe(200)
                ->and($goodWriter->endCalls)->toBe(1)
                ->and($right->completed())->toBeTrue()
                ->and(json_decode($goodWriter->body, true, flags: JSON_THROW_ON_ERROR)['request'])
                ->toBe($right->requestId);
        } finally {
            $host->shutdown();
            $wrong->complete();
            $right->complete();
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('keeps overlapping compiled Runwire host generations isolated in distinct carriers', function (): void {
    $project = foundationWebReleaseProject();
    file_put_contents(
        $project . '/routes/web.php',
        "\nRouter::get('/runwire-interleaved', [FoundationRunwireInterleavedHandler::class, 'identity']);\n",
        FILE_APPEND,
    );
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire'],
        );
        $capabilities = new \Infocyph\Runwire\RuntimeCapabilities(
            \Infocyph\Runwire\Runtime\Enum\RuntimeDriver::NATIVE,
        );
        $runtimeOne = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            $capabilities,
            'first-generation',
            generation: 1,
            concurrent: true,
        );
        $runtimeTwo = \Infocyph\Runwire\RuntimeContext::fromCapabilities(
            $capabilities,
            'second-generation',
            generation: 2,
            concurrent: true,
        );
        $hostOne = $loaded->runwireApplication($runtimeOne);
        $hostTwo = $loaded->runwireApplication($runtimeTwo);
        $requestOne = \Infocyph\Runwire\RequestContext::create($runtimeOne, requestId: 'first-generation');
        $requestTwo = \Infocyph\Runwire\RequestContext::create($runtimeTwo, requestId: 'second-generation');
        $writerOne = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
        $writerTwo = new \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter();
        $worker = static function (
            \Infocyph\Webrick\Runtime\Http\RunwireRuntimeApplication $host,
            \Infocyph\Runwire\RequestContext $request,
            \Infocyph\Foundation\Tests\Fixtures\RunwireCollectingWriter $writer,
        ): Fiber {
            return new Fiber(static function () use ($host, $request, $writer): void {
                $host->handle(new \Infocyph\Runwire\Http\HttpRequest(
                    method: 'GET',
                    target: '/runwire-interleaved',
                    version: \Infocyph\Runwire\Http\Enum\ProtocolVersion::HTTP_1_1,
                    headers: \Infocyph\Runwire\Http\Headers::fromArray(['Host' => 'release.test']),
                    body: new \Infocyph\Foundation\Tests\Fixtures\RunwireEmptyBody(),
                    context: $request,
                ), $writer, completeResponse: true);
            });
        };

        $hostOne->start();
        $hostTwo->start();

        try {
            $first = $worker($hostOne, $requestOne, $writerOne);
            $second = $worker($hostTwo, $requestTwo, $writerTwo);
            expect($first->start())->toBe('first-generation')
                ->and($second->start())->toBe('second-generation')
                ->and($requestOne->completed())->toBeFalse()
                ->and($requestTwo->completed())->toBeFalse();

            $second->resume();
            expect($second->isTerminated())->toBeTrue()
                ->and($requestTwo->completed())->toBeTrue()
                ->and($requestOne->completed())->toBeFalse();

            $first->resume();
            expect($first->isTerminated())->toBeTrue()
                ->and($requestOne->completed())->toBeTrue()
                ->and($writerOne->endCalls)->toBe(1)
                ->and($writerTwo->endCalls)->toBe(1)
                ->and(json_decode($writerOne->body, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'request' => 'first-generation',
                    'runtime' => 'first-generation',
                ])
                ->and(json_decode($writerTwo->body, true, flags: JSON_THROW_ON_ERROR))->toBe([
                    'request' => 'second-generation',
                    'runtime' => 'second-generation',
                ]);
        } finally {
            $hostOne->shutdown();
            $hostTwo->shutdown();
        }
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});

it('retains the trusted web release lease for the Runwire host lifetime', function (): void {
    $project = foundationWebReleaseProject();
    $config = [
        'app' => ['base_path' => $project, 'env' => 'production', 'debug' => false],
        '_config_cache' => false,
        'router' => [
            'files' => ['web.php'],
            'matcher' => 'fused',
            'middleware' => ['globals' => ['pre' => [], 'post' => []]],
        ],
    ];
    $manifest = $project . '/bootstrap/cache/release.json';
    $generationName = 'runwire-host-test';
    $directory = $project . '/generations/' . $generationName;

    try {
        $report = new WebReleaseCompiler()->compile(
            $config,
            $project . '/bootstrap/cache/intermix.php',
            $project . '/bootstrap/cache/router.php',
            $manifest,
            capabilities: ['runwire'],
        );
        $loaded = WebReleaseRuntime::loadPrevalidated(
            $config,
            $manifest,
            (string) $report['release_runtime_manifest_sha256'],
            foundationCapabilities: ['runwire'],
        );

        mkdir($directory, 0777, true);
        \Infocyph\Foundation\Runtime\ReleaseGenerationLease::initialize($directory);
        $lease = \Infocyph\Foundation\Runtime\ReleaseGenerationLease::acquireShared(
            $project,
            $generationName,
        );
        $generation = new \Infocyph\Foundation\Runtime\LoadedReleaseGeneration(
            $project,
            $generationName,
            lease: $lease,
        );
        $release = new WebReleaseRuntime(
            $loaded->container,
            $loaded->kernel,
            $loaded->server,
            $loaded->capabilities,
            $generation,
        );
        $weak = WeakReference::create($generation);
        $host = $release->runwireApplication(\Infocyph\Runwire\RuntimeContext::standalone());
        $secondHost = $release->runwireApplication(\Infocyph\Runwire\RuntimeContext::standalone());

        unset($lease, $generation, $release);
        gc_collect_cycles();
        expect($weak->get())->toBeNull()
            ->and(\Infocyph\Foundation\Runtime\ReleaseGenerationLease::tryExclusive(
                $project,
                $generationName,
            ))->toBeNull();

        $host->start();
        $secondHost->start();
        $host->shutdown();
        unset($host);
        gc_collect_cycles();

        expect(\Infocyph\Foundation\Runtime\ReleaseGenerationLease::tryExclusive(
            $project,
            $generationName,
        ))->toBeNull();

        $secondHost->shutdown();
        unset($secondHost);
        gc_collect_cycles();

        $exclusive = \Infocyph\Foundation\Runtime\ReleaseGenerationLease::tryExclusive(
            $project,
            $generationName,
        );
        expect($exclusive)->toBeInstanceOf(\Infocyph\Foundation\Runtime\ReleaseGenerationLease::class);
        $exclusive?->release();
    } finally {
        foundationResetWebrickProductionRegistries();
        foundationWebReleaseRemove($project);
    }
});
