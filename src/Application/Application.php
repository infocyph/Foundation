<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Config\ConfigValidationResult;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Container\ContainerFactory;
use Infocyph\Foundation\Database\DatabaseManager;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Notifications\NotificationManager;
use Infocyph\Foundation\Routing\RouterManager;
use Infocyph\Foundation\Security\SecurityManager;
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
    ) {
        $this->bindCoreServices();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function create(array $config = []): self
    {
        $repository = new ConfigLoader()->load($config);
        $container = new ContainerFactory()->create(
            is_string($repository->get('app.container_alias'))
                ? $repository->get('app.container_alias')
                : null,
        );

        $app = new self(
            config: $repository,
            container: $container,
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
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

    public function cache(): CacheManager
    {
        return $this->boot()->make(CacheManager::class);
    }

    public function cachePath(string $path = ''): string
    {
        return $this->paths()->cache($path);
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

    public function handle(Request $request): Response
    {
        return $this->boot()->http()->handle($request);
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

    public function http(): HttpKernel
    {
        return $this->boot()->make(HttpKernel::class);
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
            return $this->container->get($id);
        } catch (\Throwable $e) {
            throw new ServiceResolutionException(sprintf('Unable to resolve service "%s".', $id), previous: $e);
        }
    }

    public function notifications(): NotificationManager
    {
        return $this->boot()->make(NotificationManager::class);
    }

    public function paths(): PathManager
    {
        return $this->boot()->make(PathManager::class);
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
     *   notifications: array<string, mixed>,
     *   paths: array<string, mixed>
     * }
     */
    public function readinessReport(): array
    {
        $configResult = $this->validateConfiguration();
        $usesCacheLayer = $this->stringConfig('auth.drivers.cache', 'array') === 'cachelayer';
        $usesDbLayer = $this->stringConfig('auth.drivers.storage', 'memory') === 'dblayer';
        $databaseConfigured = $this->databaseConfigured();
        $authConnection = $this->authConnectionName();
        $authSchema = [
            'installed' => false,
            'installed_tables' => [],
            'missing_tables' => [],
        ];
        $databaseIssues = [];
        $cacheWarnings = [];

        if ($databaseConfigured) {
            try {
                $authSchema = $this->db()->authSchema()->readiness($authConnection);
            } catch (\Throwable) {
                $authSchema = [
                    'installed' => false,
                    'installed_tables' => [],
                    'missing_tables' => [],
                ];
            }
        }

        if ($usesDbLayer && $authSchema['installed'] !== true) {
            $databaseIssues[] = 'Auth DB schema is not installed.';
        }

        if ($usesCacheLayer) {
            $cacheWarnings[] = 'CacheLayer counters are not guaranteed atomic for auth lockout usage.';
        }

        $auth = $this->authReadinessReport();
        $pathIssues = $this->pathIssues();
        $notificationsTransport = $this->stringConfig('notifications.auth.transport', 'null');
        $notificationsConfigured = $notificationsTransport !== '' && $notificationsTransport !== 'null' && $notificationsTransport !== 'replace-me';

        return [
            'production_ready' => !$configResult->fails()
                && $auth['production_ready'] === true
                && (!$usesDbLayer || $authSchema['installed'] === true)
                && $databaseIssues === []
                && (!$this->isProduction() || $pathIssues === []),
            'auth' => $auth,
            'cache' => [
                'configured' => $this->config()->has('cache.stores.' . $this->stringConfig('cache.default', '')),
                'default' => $this->stringConfig('cache.default', 'memory'),
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
            'paths' => $this->paths()->all() + ['issues' => $pathIssues],
            'notifications' => [
                'configured' => $notificationsConfigured,
                'critical_types' => $this->notificationCriticalTypes(),
                'fail_silently' => (bool) $this->config()->get('notifications.auth.fail_silently', false),
                'transport' => $notificationsTransport,
            ],
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
        return PHP_SAPI === 'cli';
    }

    public function security(): SecurityManager
    {
        return $this->boot()->make(SecurityManager::class);
    }

    public function sessionsPath(string $path = ''): string
    {
        return $this->paths()->sessions($path);
    }

    public function storagePath(string $path = ''): string
    {
        return $this->paths()->storage($path);
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
        $configured = $this->config()->get('auth.dblayer.connection');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

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

    private function bindCoreServices(): void
    {
        $this->container->bind(self::class, $this, LifetimeEnum::Singleton);
        $this->container->bind(ConfigRepository::class, $this->config, LifetimeEnum::Singleton);
        $this->container->bind(Container::class, $this->container, LifetimeEnum::Singleton);
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

    private function stringConfig(string $key, string $default = ''): string
    {
        $value = $this->config()->get($key, $default);

        return is_string($value)
            ? $value
            : $default;
    }
}
