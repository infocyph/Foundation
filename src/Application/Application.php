<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Application;

use Infocyph\Foundation\Bootstrap\Bootstrapper;
use Infocyph\Foundation\Config\ConfigLoader;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Container\ContainerCacheManager;
use Infocyph\Foundation\Container\FoundationGraph;
use Infocyph\Foundation\Exception\ServiceResolutionException;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Http\HttpKernel;
use Infocyph\Foundation\Runtime\ExecutionScope;
use Infocyph\Foundation\Runtime\RuntimeContextTracker;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\ProductionContainer;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Container\ContainerInterface;

final class Application
{
    private bool $booted = false;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container|ProductionContainer $container,
        private readonly ServiceRegistry $providers,
        private readonly Bootstrapper $bootstrapper,
        private readonly RuntimeMode $runtimeMode,
    ) {
        if ($this->container instanceof Container) {
            $this->bindDevelopmentCoreServices();
            $this->container->onMissing(function (string $id): void {
                $this->activateManagedService($id);
            });
        }
    }

    /** @param array<string, mixed> $config */
    public static function create(array $config, RuntimeMode $runtimeMode): self
    {
        $sourceConfig = new ConfigLoader()->load($config);
        $context = FoundationBuildContext::fromConfig($sourceConfig, $runtimeMode);
        $container = FoundationGraph::compose($context)->development();
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Foundation graph did not produce a ConfigRepository.');
        }

        $app = new self(
            config: $runtimeConfig,
            container: $container,
            providers: new ServiceRegistry(),
            bootstrapper: new Bootstrapper(),
            runtimeMode: $runtimeMode,
        );

        $app->bootstrapper->prepare($app);

        $compiledActivation = $runtimeConfig->get('app.container.compiled_activation', 'off');
        if (is_string($compiledActivation) && strtolower($compiledActivation) === 'always') {
            $app->make(ContainerCacheManager::class)->activate();
        }

        return $app;
    }

    public function appPath(string $path = ''): string
    {
        return $this->paths()->app($path);
    }

    public function basePath(string $path = ''): string
    {
        return $this->paths()->base($path);
    }

    public function boot(): self
    {
        if (!$this->booted) {
            $this->bootstrapper->boot($this);
            $this->booted = true;
        }

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

    /**
     * Mutable container access is development-only. Production code should use
     * runtime-neutral resolution through runtime(), make(), and has().
     */
    public function container(): Container
    {
        if (!$this->container instanceof Container) {
            throw new \LogicException(
                'The mutable InterMix development container is unavailable in generated production runtime.',
            );
        }

        return $this->container;
    }

    public function databasePath(string $path = ''): string
    {
        return $this->paths()->database($path);
    }

    public function environment(): ?string
    {
        $environment = $this->config->get('app.env');

        return is_string($environment) && $environment !== '' ? $environment : null;
    }

    public function execution(): ExecutionScope
    {
        return $this->make(ExecutionScope::class);
    }

    public function handle(Request $request): Response
    {
        return $this->http()->handle($request);
    }

    public function has(string $id): bool
    {
        if ($this->container instanceof Container && $this->bootstrapper->manages($id)) {
            return $this->bootstrapper->canProvide($this, $id);
        }

        return $this->container->has($id);
    }

    public function http(): HttpKernel
    {
        if (!$this->runningInWeb()) {
            throw new \LogicException(sprintf(
                'The HTTP kernel is unavailable in the %s runtime.',
                $this->runtimeMode->value,
            ));
        }

        return $this->boot()->make(HttpKernel::class);
    }

    public function isProduction(): bool
    {
        return $this->config->isProduction();
    }

    public function logsPath(string $path = ''): string
    {
        return $this->paths()->logs($path);
    }

    /**
     * @template T of object
     * @param string|class-string<T> $id
     * @return ($id is class-string<T> ? T : mixed)
     */
    public function make(string $id): mixed
    {
        try {
            if ($this->container instanceof Container) {
                $this->activateManagedService($id);
            }

            return $this->container->get($id);
        } catch (\Throwable $exception) {
            $message = sprintf('Unable to resolve service "%s".', $id);
            if ($exception->getMessage() !== '') {
                $message .= ' ' . $exception->getMessage();
            }

            throw new ServiceResolutionException($message, previous: $exception);
        }
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

    public function register(ServiceProviderInterface $provider): self
    {
        if (!$this->container instanceof Container) {
            throw new \LogicException(
                'Providers must be composed before a generated production runtime is created.',
            );
        }

        $this->providers->add($provider);

        return $this;
    }

    public function resourcesPath(string $path = ''): string
    {
        return $this->paths()->resources($path);
    }

    public function routesPath(string $path = ''): string
    {
        return $this->paths()->routes($path);
    }

    public function runningInCli(): bool
    {
        return $this->runtimeMode === RuntimeMode::Cli;
    }

    public function runningInScheduler(): bool
    {
        return $this->runtimeMode === RuntimeMode::Scheduler;
    }

    public function runningInWeb(): bool
    {
        return $this->runtimeMode === RuntimeMode::Web;
    }

    public function runningInWorker(): bool
    {
        return $this->runtimeMode === RuntimeMode::Worker;
    }

    public function runtime(): ContainerInterface
    {
        return $this->container;
    }

    public function runtimeMode(): RuntimeMode
    {
        return $this->runtimeMode;
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

    private function activateManagedService(string $id): void
    {
        if (!$this->bootstrapper->manages($id)) {
            return;
        }

        $unavailable = $this->bootstrapper->unavailableServiceMessage($id);
        if ($unavailable !== null) {
            throw new \LogicException($unavailable);
        }
        if (!$this->bootstrapper->activateProviderFor($this, $id)) {
            throw new \LogicException(sprintf(
                'Foundation service "%s" is unavailable in the %s runtime.',
                $id,
                $this->runtimeMode->value,
            ));
        }
    }

    private function bindDevelopmentCoreServices(): void
    {
        $container = $this->container;
        if (!$container instanceof Container) {
            return;
        }

        $container->bind(self::class, $this, LifetimeEnum::Singleton);
        $container->bind(Container::class, $container, LifetimeEnum::Singleton);
        $container->bind(
            ContainerCacheManager::class,
            new ContainerCacheManager($this),
            LifetimeEnum::Singleton,
        );

        $externalState = new RuntimeContextTracker();
        $container->bind(RuntimeContextTracker::class, $externalState, LifetimeEnum::Singleton);
        $container->bind(
            ExecutionScope::class,
            new ExecutionScope($container, $externalState, $this->runtimeMode),
            LifetimeEnum::Singleton,
        );
    }
}
