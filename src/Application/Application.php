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
use Infocyph\Foundation\Runtime\LoadedReleaseGeneration;
use Infocyph\InterMix\DI\Container;
use Infocyph\InterMix\DI\Support\LifetimeEnum;
use Infocyph\Webrick\Request\Request;
use Infocyph\Webrick\Response\Response;
use Psr\Container\ContainerInterface;

final class Application
{
    private bool $booted = false;

    private ?LoadedReleaseGeneration $loadedReleaseGeneration = null;

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly ContainerInterface $container,
        private readonly ServiceRegistry $providers,
        private readonly Bootstrapper $bootstrapper,
        private readonly RuntimeMode $runtimeMode,
        bool $bindDevelopmentCore = true,
    ) {
        if ($bindDevelopmentCore && $this->container instanceof Container) {
            $this->bindDevelopmentCoreServices();
        }
    }

    /** @param array<string, mixed> $config */
    public static function create(array $config, RuntimeMode $runtimeMode): self
    {
        $sourceConfig = new ConfigLoader()->load($config);
        $context = FoundationBuildContext::fromConfig($sourceConfig, $runtimeMode);
        $builder = FoundationGraph::compose($context);
        $container = $builder->development();
        $runtimeConfig = $container->get(ConfigRepository::class);
        if (!$runtimeConfig instanceof ConfigRepository) {
            throw new \LogicException('Foundation graph did not produce a ConfigRepository.');
        }

        $bootstrapper = new Bootstrapper();
        $providers = new ServiceRegistry();
        $app = new self(
            config: $runtimeConfig,
            container: $container,
            providers: $providers,
            bootstrapper: $bootstrapper,
            runtimeMode: $runtimeMode,
        );
        $bootstrapper->compose($builder, $context, $providers);

        return $app;
    }

    public function appPath(string $path = ''): string
    {
        return $this->paths()->app($path);
    }

    public function attachLoadedReleaseGeneration(LoadedReleaseGeneration $release): self
    {
        $current = $this->loadedReleaseGeneration;
        if ($current !== null
            && ($current->releaseRoot !== $release->releaseRoot
                || $current->generation !== $release->generation
                || $current->trustedFoundationManifestSha256 !== $release->trustedFoundationManifestSha256)
        ) {
            throw new \LogicException('A Foundation process cannot change its loaded release generation in place.');
        }

        $this->loadedReleaseGeneration = $release;

        return $this;
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

    /** Mutable container access is development-only. */
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

    public function loadedReleaseGeneration(): ?LoadedReleaseGeneration
    {
        return $this->loadedReleaseGeneration;
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

    private function bindDevelopmentCoreServices(): void
    {
        $container = $this->container;
        if (!$container instanceof Container) {
            return;
        }

        if (!$container->definitions()->has(self::class)) {
            $container->bind(self::class, $this, LifetimeEnum::Singleton);
        }
        if (!$container->definitions()->has(Container::class)) {
            $container->bind(Container::class, $container, LifetimeEnum::Singleton);
        }
        if (!$container->definitions()->has(ContainerCacheManager::class)) {
            $container->bind(
                ContainerCacheManager::class,
                new ContainerCacheManager($this),
                LifetimeEnum::Singleton,
            );
        }
    }
}
