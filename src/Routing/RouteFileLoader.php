<?php

declare(strict_types=1);

namespace Infocyph\Foundation\Routing;

use Infocyph\Foundation\Config\ConfigRepository;
use Infocyph\Foundation\Filesystem\PathManager;
use Infocyph\Foundation\Support\ValueNormalizer;
use Infocyph\Webrick\Router\Definition\Attribute\AttributeRouteLoader;
use Infocyph\Webrick\Router\Definition\Registrar;
use Infocyph\Webrick\Router\Dispatch\MiddlewareAliases;

final readonly class RouteFileLoader
{
    /**
     * @param list<string> $files
     */
    public function __construct(
        private PathManager $paths,
        private ConfigRepository $config,
        private RoutePresetRegistrar $presets,
        private OAuthRouteRegistrar $oauth,
        private array $files = ['web.php', 'api.php', 'auth.php'],
    ) {}

    public function load(Registrar $router): void
    {
        $this->presets->register();
        $this->loadRoutes($router);
    }

    /**
     * Load source routes for a production release while leaving Webrick's
     * process registry narrowed to only aliases used by the selected topology.
     *
     * Full alias registration is required during route registration so Webrick
     * can preserve alias-parameter override semantics. Once discovery is
     * complete, the immutable route collection tells us the exact alias set
     * needed by HandlerCompiler and the generated artifact.
     */
    public function loadForRelease(Registrar $router): void
    {
        $this->presets->register();
        $this->loadRoutes($router);
        $requirements = $this->releaseMiddlewareRequirements($router);

        MiddlewareAliases::reset();
        $this->presets->register($requirements);
    }

    /** @return list<class-string> */
    private function attributeClasses(mixed $classes): array
    {
        $resolved = [];

        foreach (ValueNormalizer::stringList($classes) as $class) {
            if (!class_exists($class)) {
                continue;
            }

            /** @var class-string $class */
            $resolved[] = $class;
        }

        return $resolved;
    }

    /** @return array<string, string> */
    private function attributeDirectories(mixed $directories): array
    {
        if (!is_array($directories)) {
            return [];
        }

        $resolved = [];

        foreach ($directories as $namespace => $path) {
            if (!is_string($namespace) || !is_string($path) || $namespace === '' || $path === '') {
                continue;
            }

            $resolved[$namespace] = $path;
        }

        return $resolved;
    }

    private function loadAttributeRoutes(Registrar $router): void
    {
        $attributes = ValueNormalizer::associativeArray($this->config->get('router.attributes', []));
        if (!ValueNormalizer::bool($attributes['enabled'] ?? false, false)) {
            return;
        }

        $classes = $this->attributeClasses($attributes['classes'] ?? []);

        if ($classes !== []) {
            AttributeRouteLoader::register($router, $classes);
        }

        $directories = $this->attributeDirectories($attributes['directories'] ?? []);
        if ($directories === []) {
            $directories = [
                'App\\Http\\Controllers\\' => $this->paths->app('Http/Controllers'),
            ];
        }

        $filter = ValueNormalizer::bool($attributes['controller_file_filter'] ?? true, true)
            ? AttributeRouteLoader::controllerFileFilter()
            : null;

        AttributeRouteLoader::registerFromDirs($router, $directories, $filter);
    }

    private function loadRoutes(Registrar $router): void
    {
        $this->oauth->register($router);

        foreach ($this->files as $file) {
            $path = $this->paths->routes($file);

            if (!is_file($path)) {
                continue;
            }

            $presets = $this->presets;

            require $path;
        }

        $this->loadAttributeRoutes($router);
    }

    /** @return list<string> */
    private function releaseMiddlewareRequirements(Registrar $router): array
    {
        $requirements = [];
        foreach ($router->compile()->all() as $route) {
            foreach ($route->getMiddlewares() as $middleware) {
                if (!is_string($middleware)) {
                    continue;
                }

                $alias = strtolower(trim(explode(':', $middleware, 2)[0]));
                if ($alias !== '' && MiddlewareAliases::has($alias)) {
                    $requirements[$alias] = true;
                }
            }
        }
        ksort($requirements, SORT_STRING);

        return array_keys($requirements);
    }
}
