<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Console\Support;

use Infocyph\Foundation\Application\Application;
use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Routing\RouteCachePath;
use Infocyph\Foundation\Routing\RoutePresetRegistrar;
use Infocyph\Foundation\Routing\WebrickMiddlewareFactory;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Constants\MatcherModeEnum;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Support\RouteCache;
use Psr\Log\NullLogger;

final readonly class RouteCacheManager
{
    public function __construct(private Application $application) {}

    public function cachePath(?string $path): string
    {
        return $path !== null && $path !== ''
            ? $path
            : RouteCachePath::for($this->application->config());
    }

    public function clear(string $matcher, string $cache, bool $aggressive = false): bool
    {
        return RouteCache::clear([
            'matcher' => $this->matcher($matcher),
            'cache' => $cache,
            'aggressive' => $aggressive,
        ]);
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

    public function write(string $matcher, string $cache, ?string $routes = null): string
    {
        $config = $this->application->config();
        $middleware = $this->application->make(WebrickMiddlewareFactory::class);

        return RouteCache::build([
            'matcher' => $this->matcher($matcher),
            'cache' => $cache,
            'register' => function (Registrar $registrar) use ($config, $routes): void {
                $this->loadRoutes($registrar, $config, $this->routeFiles($config, $routes));
            },
            'signKey' => ValueNormalizer::nullableString($config->get('router.signed_urls.key')),
            'signedDefaultTtl' => ValueNormalizer::int($config->get('router.signed_urls.default_ttl'), 900),
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
    }

    private function absolute(string $path): bool
    {
        return preg_match('/^(?:[A-Z]:[\\\\\/]|\\\\\\\\|\/)/i', $path) === 1;
    }

    /**
     * @return list<class-string>
     */
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

    private function loadAttributeRoutes(
        Registrar $registrar,
        ConfigRepository $config,
        PathManager $paths,
    ): void {
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

    /**
     * @param list<string> $files
     */
    private function loadRoutes(Registrar $registrar, ConfigRepository $config, array $files): void
    {
        $paths = $this->application->make(PathManager::class);
        $presets = $this->application->make(RoutePresetRegistrar::class);

        $presets->register();
        $router = new RouteCacheRouter($registrar, $presets, $config);

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
    }

    /**
     * @return list<string>
     */
    private function routeFiles(ConfigRepository $config, ?string $routes): array
    {
        if ($routes !== null && $routes !== '') {
            return array_values(array_filter(array_map(
                trim(...),
                explode(',', $routes),
            )));
        }

        return ValueNormalizer::stringList($config->get('router.files', ['api.php']));
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
}
