<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Auth\Otp\OtpManager;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Communication\CommunicationManager;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidationResult;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Console\Support\ModuleManifestManager;
use Infocyph\Foundation\Container\ContainerFactory;
use Infocyph\Foundation\Data\DataManager;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Filesystem\FilesystemManager;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Http\JsonDispatch\JsonDispatchResponseFactory;
use Infocyph\Foundation\Identifiers\IdentifierManager;
use Infocyph\Foundation\Messaging\MessagingManager;
use Infocyph\Foundation\Notifications\NotificationManager;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Runtime\RuntimeContextResetter;
use Infocyph\Foundation\Security\SecurityManager;
use Infocyph\Foundation\Session\BrowserSession;
use Infocyph\Foundation\Session\SessionDatabaseSchema;
use Infocyph\Foundation\Session\SessionManager;
use Infocyph\Foundation\Testing\TestKit;
use Infocyph\Foundation\Validation\ValidationManager;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;

final class Application
{
    private bool $booted = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container,
        private readonly ServiceRegistry $providers,
        private readonly Bootstrapper $bootstrapper,
        private readonly RuntimeMode $runtimeMode,
    ) {
        $this->bindCoreServices();
    }

    /**
     * @param array<string, mixed> $config
     * @param RuntimeMode $runtimeMode Explicit immutable execution path.
     */
    public static function create(array $config, RuntimeMode $runtimeMode): self
    {
        $repository = new ConfigLoader()->load($config);
        $container = new ContainerFactory()->create($repository);

        $app = new self(
            config: $repository,
            container: $container,
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
            runtimeMode: $runtimeMode,
        );

        $app->bootstrapper->prepare($app);

        return $app;
    }

    public function appPath(string $path = ''): string
    {
        return $this->paths()->app($path);
    }

    public function auth(): AuthServices
    {
        return $this->boot()->make(AuthServices::class);
    }

    public function authActions(): AuthActions
    {
        return $this->boot()->make(AuthActions::class);
    }

    public function authManager(): AuthManager
    {
        return $this->boot()->make(AuthManager::class);
    }

    public function basePath(string $path = ''): string
    {
        return $this->paths()->base($path);
    }

    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        $this->bootstrapper->boot($this);
        $this->booted = true;

        return $this;
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function bootstrapPath(string $path = ''): string
    {
        return $this->paths()->bootstrap($path);
    }

    public function browserSession(): BrowserSession
    {
        return $this->session()->current();
    }

    public function cache(): CacheManager
    {
        return $this->boot()->make(CacheManager::class);
    }

    public function cachePath(string $path = ''): string
    {
        return $this->paths()->cache($path);
    }

    public function communication(): CommunicationManager
    {
        return $this->boot()->make(CommunicationManager::class);
    }

    public function config(): ConfigRepository
    {
        return $this->config;
    }

    public function configPath(string $path = ''): string
    {
        return $this->paths()->config($path);
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function data(): DataManager
    {
        return $this->boot()->make(DataManager::class);
    }

    public function databasePath(string $path = ''): string
    {
        return $this->paths()->database($path);
    }

    public function db(): DatabaseManager
    {
        return $this->boot()->make(DatabaseManager::class);
    }

    public function environment(): ?string
    {
        $environment = $this->config->get('app.env');

        return is_string($environment) && $environment !== ''
            ? $environment
            : null;
    }

    public function files(): FilesystemManager
    {
        return $this->boot()->make(FilesystemManager::class);
    }

    public function handle(Request $request): Response
    {
        return $this->http()->handle($request);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id) || $this->bootstrapper->canProvide($this, $id);
    }

    public function http(): HttpKernel
    {
        if ($this->runningInConsole()) {
            throw new \LogicException('The HTTP kernel is unavailable in the console runtime.');
        }

        return $this->boot()->make(HttpKernel::class);
    }

    public function ids(): IdentifierManager
    {
        return $this->boot()->make(IdentifierManager::class);
    }

    public function isProduction(): bool
    {
        return $this->config()->isProduction();
    }

    public function logsPath(string $path = ''): string
    {
        return $this->paths()->logs($path);
    }

    /**
     * @template T of object
     *
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function make(string $id): mixed
    {
        try {
            if (!$this->container->has($id)) {
                $activated = $this->bootstrapper->activateProviderFor($this, $id);
                $unavailable = $activated ? null : $this->bootstrapper->unavailableServiceMessage($id);
                if ($unavailable !== null) {
                    throw new \LogicException($unavailable);
                }
            }

            return $this->container->get($id);
        } catch (\Throwable $e) {
            $message = sprintf('Unable to resolve service "%s".', $id);
            if ($e->getMessage() !== '') {
                $message .= ' ' . $e->getMessage();
            }

            throw new ServiceResolutionException($message, previous: $e);
        }
    }

    public function messaging(): MessagingManager
    {
        return $this->boot()->make(MessagingManager::class);
    }

    public function notifications(): NotificationManager
    {
        return $this->boot()->make(NotificationManager::class);
    }

    public function otp(): OtpManager
    {
        return $this->boot()->make(OtpManager::class);
    }

    public function paths(): PathManager
    {
        return $this->make(PathManager::class);
    }

    public function providers(): ServiceRegistry
    {
        return $this->providers;
    }

    public function publicPath(string $path = ''): string
    {
        return $this->paths()->public($path);
    }

    /**
     * @return array{
     *   production_ready: bool,
     *   auth: array<string, mixed>,
     *   cache: array<string, mixed>,
     *   config: array<string, mixed>,
     *   database: array<string, mixed>,
     *   logging: array<string, mixed>,
     *   messaging: array<string, mixed>,
     *   migrations: array<string, mixed>,
     *   modules: array<string, mixed>,
     *   notifications: array<string, mixed>,
     *   optimization: array<string, mixed>,
     *   paths: array<string, mixed>,
     *   resources: array<string, mixed>,
     *   sessions: array<string, mixed>
     * }
     */
    public function readinessReport(): array
    {
        $configResult = $this->validateConfiguration();
        $usesSharedCache = $this->stringConfig('auth.drivers.cache', 'array') === 'cache';
        $usesDatabase = $this->stringConfig('auth.drivers.storage', 'memory') === 'database';
        $databaseConfigured = $this->databaseConfigured();
        $authConnection = $this->authConnectionName();
        $authSchema = $this->authSchemaReadiness($databaseConfigured, $authConnection);
        $databaseIssues = $usesDatabase && $authSchema['installed'] !== true
            ? ['Auth DB schema is not installed.']
            : [];
        $cacheWarnings = $this->cacheReadinessWarnings($usesSharedCache);
        $clusterStatus = $this->clusterReadinessStatus();

        $auth = $this->authReadinessReport();
        $pathIssues = $this->pathIssues();
        $notificationsTransport = $this->stringConfig('notifications.auth.transport', 'null');
        $notificationsConfigured = $notificationsTransport !== '' && $notificationsTransport !== 'null' && $notificationsTransport !== 'replace-me';
        $migrations = $this->migrationReadiness($databaseConfigured);
        $sessions = $this->sessionReadiness($databaseConfigured);
        $messaging = $this->messagingReadiness();

        return [
            'production_ready' => $this->productionReady(
                $configResult,
                $auth,
                $usesDatabase,
                $authSchema,
                $databaseIssues,
                $migrations,
                $sessions,
                $pathIssues,
            ),
            'auth' => $auth,
            'cache' => [
                'configured' => $this->config()->has('cache.stores.' . $this->stringConfig('cache.default', '')),
                'default' => $this->stringConfig('cache.default', 'memory'),
                'clusters' => $clusterStatus,
                'warnings' => $cacheWarnings,
            ],
            'config' => [
                'issues' => $configResult->messages(),
                'valid' => !$configResult->fails(),
            ],
            'database' => [
                'auth_connection' => $authConnection,
                'auth_schema' => $authSchema,
                'auth_schema_installed' => $authSchema['installed'],
                'configured' => $databaseConfigured,
                'default' => $this->config()->get('database.default'),
                'issues' => $databaseIssues,
            ],
            'logging' => [
                'configured' => $this->stringConfig('logging.driver', 'null') !== 'null',
                'driver' => $this->stringConfig('logging.driver', 'null'),
                'level' => $this->stringConfig('logging.level', 'warning'),
            ],
            'messaging' => $messaging,
            'migrations' => $migrations,
            'modules' => $this->moduleReadiness(),
            'paths' => $this->paths()->all() + ['issues' => $pathIssues],
            'notifications' => [
                'configured' => $notificationsConfigured,
                'critical_types' => $this->notificationCriticalTypes(),
                'fail_silently' => (bool) $this->config()->get('notifications.auth.fail_silently', false),
                'transport' => $notificationsTransport,
            ],
            'optimization' => $this->optimizationReadiness(),
            'resources' => [
                'application_version' => $this->stringConfig(
                    'responses.json_dispatch.application_version',
                    '1.0.0',
                ),
                'configured' => is_file($this->configPath('responses.php')),
                'specification' => JsonDispatchResponseFactory::SPECIFICATION_VERSION,
                'tunnel_errors' => (bool) $this->config()->get(
                    'responses.json_dispatch.tunnel_errors',
                    false,
                ),
                'vendor' => $this->stringConfig('responses.json_dispatch.vendor', 'infocyph'),
            ],
            'sessions' => $sessions,
        ];
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $this->providers->add($provider);

        return $this;
    }

    public function resourcesPath(string $path = ''): string
    {
        return $this->paths()->resources($path);
    }

    public function responses(): JsonDispatchResponseFactory
    {
        return $this->boot()->make(JsonDispatchResponseFactory::class);
    }

    public function router(): RouterManager
    {
        return $this->boot()->make(RouterManager::class);
    }

    public function routesPath(string $path = ''): string
    {
        return $this->paths()->routes($path);
    }

    public function runningInConsole(): bool
    {
        return $this->runtimeMode === RuntimeMode::Console;
    }

    public function runningInWeb(): bool
    {
        return $this->runtimeMode === RuntimeMode::Web;
    }

    public function runtimeMode(): RuntimeMode
    {
        return $this->runtimeMode;
    }

    public function security(): SecurityManager
    {
        return $this->boot()->make(SecurityManager::class);
    }

    public function session(): SessionManager
    {
        return $this->boot()->make(SessionManager::class);
    }

    public function sessionsPath(string $path = ''): string
    {
        return $this->paths()->sessions($path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->paths()->storage($path);
    }

    public function testing(): TestKit
    {
        return new TestKit($this);
    }

    public function uploadsPath(string $path = ''): string
    {
        return $this->paths()->uploads($path);
    }

    public function validateConfiguration(): ConfigValidationResult
    {
        return new ConfigValidator($this->config)->validate();
    }

    public function validator(): ValidationManager
    {
        return $this->boot()->make(ValidationManager::class);
    }

    private function authConnectionName(): ?string
    {
        $default = $this->config()->get('database.default');

        return is_string($default) && $default !== ''
            ? $default
            : null;
    }

    /**
     * @return array{
     *   production_ready: bool,
     *   issues: list<string>,
     *   drivers: array<string, string>
     * }
     */
    private function authReadinessReport(): array
    {
        try {
            return $this->authManager()->readinessReport();
        } catch (\Throwable $e) {
            $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();

            return [
                'production_ready' => false,
                'issues' => [$message !== '' ? $message : 'Unable to resolve auth services for readiness reporting.'],
                'drivers' => [],
            ];
        }
    }

    /**
     * @return array{installed:bool,installed_tables:list<string>,missing_tables:list<string>}
     */
    private function authSchemaReadiness(bool $databaseConfigured, ?string $connection): array
    {
        if ($databaseConfigured) {
            try {
                return $this->db()->authSchema()->readiness($connection);
            } catch (\Throwable) {
            }
        }

        return [
            'installed' => false,
            'installed_tables' => [],
            'missing_tables' => [],
        ];
    }

    private function bindCoreServices(): void
    {
        $this->container->bind(self::class, $this, LifetimeEnum::Singleton);
        $this->container->bind(RuntimeMode::class, $this->runtimeMode, LifetimeEnum::Singleton);
        $this->container->bind(ConfigRepository::class, $this->config, LifetimeEnum::Singleton);
        $this->container->bind(Container::class, $this->container, LifetimeEnum::Singleton);
        $this->container->bind(
            RuntimeContextResetter::class,
            new RuntimeContextResetter($this->container),
            LifetimeEnum::Singleton,
        );
    }

    /**
     * @return list<string>
     */
    private function cacheReadinessWarnings(bool $usesSharedCache): array
    {
        return $usesSharedCache && $this->stringConfig('cache.default_counter', '') === ''
            ? ['The default cache store does not guarantee atomic auth lockout counters.']
            : [];
    }

    /**
     * @return array<string, array<string, int|string|null>>
     */
    private function clusterReadinessStatus(): array
    {
        $configured = $this->config()->get('cache.clusters', []);
        if (!is_array($configured)) {
            return [];
        }

        $statuses = [];
        foreach (array_keys($configured) as $name) {
            if (!is_string($name)) {
                continue;
            }

            try {
                $status = $this->cache()->clusterStatus($name);
                $statuses[$name] = [
                    'cursor' => $status->cursor,
                    'cursor_updated_at' => $status->cursorUpdatedAt,
                    'pending_events' => $status->pendingEventCount,
                    'last_consume_count' => $status->lastConsumeCount,
                    'last_consume_error' => $status->lastConsumeError,
                    'last_recovery_at' => $status->lastRecoveryAt,
                ];
            } catch (\Throwable $exception) {
                $statuses[$name] = ['error' => $exception->getMessage()];
            }
        }

        return $statuses;
    }

    private function configuredMapCount(string $key): int
    {
        $configured = $this->config()->get($key, []);

        return is_array($configured) ? count($configured) : 0;
    }

    private function databaseConfigured(): bool
    {
        $connection = $this->authConnectionName();
        if ($connection === null) {
            return false;
        }

        $configured = $this->config()->get('database.connections.' . $connection);

        return is_array($configured) && $configured !== [];
    }

    /**
     * @return array{configured:bool,handlers:int,listeners:int,routes:int,scheduled_messages:int}
     */
    private function messagingReadiness(): array
    {
        $handlers = $this->configuredMapCount('messaging.handlers');
        $listeners = $this->configuredMapCount('messaging.listeners');
        $routes = $this->configuredMapCount('messaging.routes');
        $scheduled = $this->configuredMapCount('messaging.scheduled_messages');

        return [
            'configured' => $handlers + $listeners + $routes + $scheduled > 0
                || (bool) $this->config()->get('messaging.forward_auth_events', false),
            'handlers' => $handlers,
            'listeners' => $listeners,
            'routes' => $routes,
            'scheduled_messages' => $scheduled,
        ];
    }

    /**
     * @return array{configured:bool,count:int,issues:list<string>,pending:list<string>}
     */
    private function migrationReadiness(bool $databaseConfigured): array
    {
        $configured = $this->config()->get('database.migrations.classes', []);
        $count = is_array($configured) ? count($configured) : 0;
        $report = [
            'configured' => $count > 0,
            'count' => $count,
            'issues' => [],
            'pending' => [],
        ];
        if ($count === 0) {
            return $report;
        }
        if (!$databaseConfigured) {
            $report['issues'][] = 'Migrations are registered without a default database connection.';

            return $report;
        }

        try {
            foreach ($this->db()->migrations()->runner()->status() as $migration) {
                if ($migration['applied'] !== true) {
                    $report['pending'][] = $migration['id'];
                }
            }
        } catch (\Throwable $exception) {
            $report['issues'][] = $exception->getPrevious()?->getMessage()
                ?? $exception->getMessage();
        }

        return $report;
    }

    /**
     * @return array{compiled:bool,count:int,issues:list<string>,path:string}
     */
    private function moduleReadiness(): array
    {
        $path = $this->basePath('bootstrap/cache/modules.php');
        $report = [
            'compiled' => is_file($path),
            'count' => 0,
            'issues' => [],
            'path' => $path,
        ];
        if (!$report['compiled']) {
            return $report;
        }

        try {
            $report['count'] = count(new ModuleManifestManager($this)->load());
        } catch (\Throwable $exception) {
            $report['issues'][] = $exception->getMessage();
        }

        return $report;
    }

    /**
     * @return list<string>
     */
    private function notificationCriticalTypes(): array
    {
        $configured = $this->config()->get('notifications.auth.critical_types', []);
        if (!is_array($configured)) {
            return [];
        }

        $types = [];
        foreach ($configured as $type) {
            if (!is_string($type) || $type === '') {
                continue;
            }

            $types[] = $type;
        }

        return $types;
    }

    /**
     * @return array{
     *   commands:bool,
     *   config:bool,
     *   modules:bool,
     *   routes:bool,
     *   schedule:bool
     * }
     */
    private function optimizationReadiness(): array
    {
        return [
            'commands' => is_file($this->basePath('bootstrap/cache/console/commands.php')),
            'config' => $this->config()->isCompiled(),
            'modules' => is_file($this->basePath('bootstrap/cache/modules.php')),
            'routes' => \Infocyph\Foundation\Routing\RouteCachePath::isWarm($this->config()),
            'schedule' => is_file($this->basePath('bootstrap/cache/console/schedule.php')),
        ];
    }

    /**
     * @return list<string>
     */
    private function pathIssues(): array
    {
        $issues = [];

        foreach ($this->paths()->runtimeDirectories() as $directory) {
            if (!is_dir($directory)) {
                $issues[] = sprintf('Runtime directory "%s" does not exist.', $directory);

                continue;
            }

            if (!is_writable($directory)) {
                $issues[] = sprintf('Runtime directory "%s" is not writable.', $directory);
            }
        }

        return $issues;
    }

    /**
     * @param array<string, mixed> $auth
     * @param array<string, mixed> $authSchema
     * @param list<string> $databaseIssues
     * @param array<string, mixed> $migrations
     * @param array<string, mixed> $sessions
     * @param list<string> $pathIssues
     */
    private function productionReady(
        ConfigValidationResult $config,
        array $auth,
        bool $usesDatabase,
        array $authSchema,
        array $databaseIssues,
        array $migrations,
        array $sessions,
        array $pathIssues,
    ): bool {
        return !$config->fails()
            && $auth['production_ready'] === true
            && (!$usesDatabase || $authSchema['installed'] === true)
            && $databaseIssues === []
            && $migrations['issues'] === []
            && $migrations['pending'] === []
            && $sessions['issues'] === []
            && (!$this->isProduction() || $pathIssues === []);
    }

    /**
     * @return array{
     *   configured:bool,
     *   driver:string,
     *   issues:list<string>,
     *   schema:array{installed:bool,table:string,connection:string|null}|null
     * }
     */
    private function sessionReadiness(bool $databaseConfigured): array
    {
        $driver = $this->stringConfig('session.driver', 'array');
        $configured = is_file($this->configPath('session.php')) || $driver !== 'array';
        $report = [
            'configured' => $configured,
            'driver' => $driver,
            'issues' => [],
            'schema' => null,
        ];
        if (!$configured || $driver !== 'database') {
            return $report;
        }
        if (!$databaseConfigured) {
            $report['issues'][] = 'The database session driver requires a default database connection.';

            return $report;
        }

        try {
            $report['schema'] = $this->make(SessionDatabaseSchema::class)->readiness();
            if ($report['schema']['installed'] !== true) {
                $report['issues'][] = 'The browser-session database schema is not installed.';
            }
        } catch (\Throwable $exception) {
            $report['issues'][] = $exception->getPrevious()?->getMessage()
                ?? $exception->getMessage();
        }

        return $report;
    }

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config()->get($key, $default);

        return is_string($value)
            ? $value
            : $default;
    }
}
