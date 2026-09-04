<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Foundation;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Facade\Router;
use Infocyph\Webrick\Router\Route\Collection;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

final readonly class RouteCacheManager
{
    public function __construct(private Application $application) {}

    public function cachePath(?string $path): string
    {
        return $path !== null && $path !== '' ? $path : RouteCachePath::for($this->application->config());
    }

    public function clear(string $matcher, string $cache, bool $aggressive = false): bool
    {
        return RouteCache::clear([
            'matcher' => $this->matcher($matcher),
            'cache' => $cache,
            'aggressive' => $aggressive,
        ]);
    }

    public function clearAll(): bool
    {
        $matcher = $this->matcher(null);
        $cache = $this->cachePath(null);
        $directory = $matcher === MatcherModeEnum::SHARDED->value ? rtrim($cache, '/\\') : dirname($cache);

        return $this->clear(MatcherModeEnum::SHARDED->value, $directory, true);
    }

    public function configuredMatcher(): string
    {
        return ValueNormalizer::string($this->application->config()->get('router.matcher'), 'fused');
    }

    public function matcher(?string $matcher): string
    {
        $matcher = strtolower($matcher ?? $this->configuredMatcher());
        if (!in_array($matcher, MatcherModeEnum::values(), true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid matcher "%s". Allowed values: %s.',
                $matcher,
                implode(', ', MatcherModeEnum::values()),
            ));
        }

        return $matcher;
    }

    public function routes(?string $routes = null): Collection
    {
        if (!$this->application->runningInWeb()) {
            return $this->webManager()->routes($routes);
        }

        $config = $this->application->config();
        $collection = new Collection();
        $registrar = new Registrar($collection);
        Router::withScopedInstance($registrar, function () use ($config, $registrar, $routes): void {
            $this->loadRoutes($registrar, $config, $this->routeFiles($config, $routes));
        });

        return $collection;
    }

    public function write(string $matcher, string $cache, ?string $routes = null): string
    {
        if (!$this->application->runningInWeb()) {
            return $this->webManager()->write($matcher, $cache, $routes);
        }

        $config = $this->application->config();
        $middleware = $this->application->make(WebrickMiddlewareFactory::class);

        $path = RouteCache::build([
            'matcher' => $this->matcher($matcher),
            'cache' => $cache,
            'register' => function (Registrar $registrar) use ($config, $routes): void {
                $this->loadRoutes(
                    $registrar,
                    $config,
                    $this->routeFiles($config, $routes),
                    registerRequiredAliasesOnly: true,
                );
            },
            'signKey' => ValueNormalizer::nullableString($config->get('router.signed_urls.key')),
            'signedDefaultTtl' => $this->optionalInt($config->get('router.signed_urls.default_ttl')),
            'signedUrlConfig' => $this->signedUrlOptions(
                ValueNormalizer::associativeArray($config->get('router.signed_urls.options', [])),
            ),
            'urlBaseUri' => ValueNormalizer::string($config->get('router.url_base_uri'), ''),
            'registrarOptions' => [
                'autoSlashRedirect' => (bool) $config->get('router.auto_slash_redirect', false),
                'exposeUrlServices' => (bool) $config->get('router.expose_url_services', false),
            ],
            'preGlobal' => $middleware->preGlobal(),
            'postGlobal' => $middleware->postGlobal(),
            'fallbackAliasesFromRegistrar' => true,
            'logger' => new NullLogger(),
        ]);
        RouteCachePath::markFresh($config);

        return $path;
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /** @return list<class-string> */
    private function attributeClasses(mixed $classes): array
    {
        $resolved = [];
        foreach (ValueNormalizer::stringList($classes) as $class) {
            if (class_exists($class)) {
                $resolved[] = $class;
            }
        }

        return $resolved;
    }

    private function loadAttributeRoutes(Registrar $registrar, ConfigRepository $config, PathManager $paths): void
    {
        $attributes = ValueNormalizer::associativeArray($config->get('router.attributes', []));
        if (!ValueNormalizer::bool($attributes['enabled'] ?? false, false)) {
            return;
        }

        $classes = $this->attributeClasses($attributes['classes'] ?? []);
        if ($classes !== []) {
            AttributeRouteLoader::register($registrar, $classes);
        }

        $directories = [];
        foreach (ValueNormalizer::associativeArray($attributes['directories'] ?? []) as $namespace => $path) {
            if (is_string($path) && $path !== '') {
                $directories[$namespace] = $path;
            }
        }

        AttributeRouteLoader::registerFromDirs(
            $registrar,
            $directories === [] ? ['App\\Http\\Controllers\\' => $paths->app('Http/Controllers')] : $directories,
            ValueNormalizer::bool($attributes['controller_file_filter'] ?? true, true)
                ? AttributeRouteLoader::controllerFileFilter()
                : null,
        );
    }

    /** @param list<string> $files */
    private function loadRoutes(
        Registrar $registrar,
        ConfigRepository $config,
        array $files,
        bool $registerRequiredAliasesOnly = false,
    ): void {
        $paths = $this->application->make(PathManager::class);
        $presets = $this->application->make(RoutePresetRegistrar::class);
        if (!$registerRequiredAliasesOnly) {
            $presets->register();
        }
        $this->application->make(OAuthRouteRegistrar::class)->register($registrar);
        $router = $registrar;

        foreach ($files as $file) {
            $path = match (true) {
                $this->absolute($file) => $file,
                str_contains($file, '/'), str_contains($file, '\\') => $paths->base($file),
                default => $paths->routes($file),
            };
            if (is_file($path)) {
                require $path;
            }
        }

        $this->loadAttributeRoutes($registrar, $config, $paths);

        if ($registerRequiredAliasesOnly) {
            $this->application->make(RouteMiddlewareRegistrar::class)->register(
                $this->middlewareRequirements($registrar),
            );
        }
    }

    /** @return list<string> */
    private function middlewareRequirements(Registrar $registrar): array
    {
        $requirements = [];
        foreach ($registrar->compile()->all() as $route) {
            foreach ($route->getMiddlewares() as $middleware) {
                if (!is_string($middleware)) {
                    continue;
                }

                $alias = strtolower(trim(explode(':', $middleware, 2)[0]));
                if ($alias !== '') {
                    $requirements[$alias] = true;
                }
            }
        }

        return array_keys($requirements);
    }

    private function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_string($value) && $value !== '' && preg_match('/^-?(?:0|[1-9]\d*)$/D', $value) === 1
            ? (int) $value
            : null;
    }

    /** @return list<string> */
    private function routeFiles(ConfigRepository $config, ?string $routes): array
    {
        if ($routes !== null && $routes !== '') {
            return array_values(array_filter(array_map(trim(...), explode(',', $routes))));
        }

        return ValueNormalizer::stringList($config->get('router.files', ['web.php', 'api.php', 'auth.php']));
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function signedUrlOptions(array $options): array
    {
        $normalized = [];
        foreach ($options as $key => $value) {
            $normalized[match ($key) {
                'default_ttl' => 'defaultTtl',
                'expiry_param' => 'expiryParam',
                'generation_key' => 'generationKey',
                'ignored_query_params' => 'ignoredQueryParams',
                'payload_mode' => 'payloadMode',
                'signature_param' => 'signatureParam',
                'verification_keys' => 'verificationKeys',
                default => $key,
            }] = $value;
        }

        return $normalized;
    }

    private function webManager(): self
    {
        $config = $this->application->config()->all();
        $config['_config_cache'] = false;

        return new self(Foundation::web($config));
    }
}
