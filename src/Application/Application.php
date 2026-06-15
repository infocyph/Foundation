<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Auth\AuthManager;
use Infocyph\Foundation\Auth\AuthServices;
use Infocyph\Foundation\Auth\Http\AuthActions;
use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Cache\CacheManager;
use Infocyph\Foundation\Config\ConfigValidationResult;
use Infocyph\Foundation\Config\ConfigValidator;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
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
        $repository = (new ConfigLoader())->load($config);
        $container = (new ContainerFactory())->create(
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

    public function cache(): CacheManager
    {
        return $this->boot()->service('foundation.cache');
    }

    public function cachePath(string $path = ''): string
    {
        return $this->paths()->cache($path);
    }

    public function config(): ConfigRepository
    {
        return $this->config;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function db(): DatabaseManager
    {
        return $this->boot()->service('foundation.db');
    }

    public function environment(): ?string
    {
        $environment = $this->config->get('app.env');

        return is_string($environment) && $environment !== ''
            ? $environment
            : null;
    }

    public function booted(): bool
    {
        return $this->booted;
    }

    public function handle(Request $request): Response
    {
        return $this->boot()->http()->handle($request);
    }

    public function http(): HttpKernel
    {
        return $this->boot()->service('foundation.http');
    }

    public function isProduction(): bool
    {
        return $this->config()->isProduction();
    }

    public function has(string $id): bool
    {
        return $this->container->has($id);
    }

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
        return $this->boot()->service('foundation.notifications');
    }

    public function paths(): PathManager
    {
        return $this->boot()->service('foundation.paths');
    }

    public function providers(): ServiceRegistry
    {
        return $this->providers;
    }

    public function register(ServiceProviderInterface $provider): self
    {
        $this->providers->add($provider);

        return $this;
    }

    public function router(): RouterManager
    {
        return $this->boot()->service('foundation.router');
    }

    /**
     * @return array{
     *   production_ready: bool,
     *   auth: array<string, mixed>,
     *   cache: array<string, mixed>,
     *   config: array<string, mixed>,
     *   database: array<string, mixed>,
     *   notifications: array<string, mixed>
     * }
     */
    public function readinessReport(): array
    {
        $configResult = $this->validateConfiguration();
        $usesCacheLayer = (string) $this->config()->get('auth.drivers.cache', 'array') === 'cachelayer';
        $usesDbLayer = (string) $this->config()->get('auth.drivers.storage', 'memory') === 'dblayer';
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

        if ($usesDbLayer && ($authSchema['installed'] ?? false) !== true) {
            $databaseIssues[] = 'Auth DB schema is not installed.';
        }

        if ($usesCacheLayer) {
            $cacheWarnings[] = 'CacheLayer counters are not guaranteed atomic for auth lockout usage.';
        }

        $auth = $this->authManager()->readinessReport();
        $notificationsTransport = (string) $this->config()->get('notifications.auth.transport', 'null');
        $notificationsConfigured = $notificationsTransport !== '' && $notificationsTransport !== 'null' && $notificationsTransport !== 'replace-me';

        return [
            'production_ready' => !$configResult->fails()
                && ($auth['production_ready'] ?? false) === true
                && (!$usesDbLayer || ($authSchema['installed'] ?? false) === true)
                && $databaseIssues === [],
            'auth' => $auth,
            'cache' => [
                'configured' => $this->config()->has('cache.stores.' . (string) $this->config()->get('cache.default', '')),
                'default' => $this->config()->get('cache.default', 'memory'),
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
            'notifications' => [
                'configured' => $notificationsConfigured,
                'critical_types' => $this->notificationCriticalTypes(),
                'fail_silently' => (bool) $this->config()->get('notifications.auth.fail_silently', false),
                'transport' => $notificationsTransport,
            ],
        ];
    }

    public function runningInConsole(): bool
    {
        return PHP_SAPI === 'cli';
    }

    public function security(): SecurityManager
    {
        return $this->boot()->service('foundation.security');
    }

    public function storagePath(string $path = ''): string
    {
        return $this->paths()->storage($path);
    }

    public function validator(): ValidationManager
    {
        return $this->boot()->service('foundation.validator');
    }

    public function validateConfiguration(): ConfigValidationResult
    {
        return (new ConfigValidator($this->config))->validate();
    }

    private function bindCoreServices(): void
    {
        $this->container->bind(self::class, $this, LifetimeEnum::Singleton);
        $this->container->bind(ConfigRepository::class, $this->config, LifetimeEnum::Singleton);
        $this->container->bind(Container::class, $this->container, LifetimeEnum::Singleton);
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
     * @template T of object
     * @param class-string<T>|string $id
     * @return T|mixed
     */
    private function service(string $id): mixed
    {
        return $this->make($id);
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
}
